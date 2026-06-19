<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkspaceSetting;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if ($user->status === 'disabled') {
            throw ValidationException::withMessages([
                'email' => 'This account is disabled.',
            ]);
        }

        // MFA challenge: when the user has enrolled, password alone doesn't
        // issue a token. Instead we cache a short-lived challenge token tied
        // to user_id (5 min), return mfa_required + pending_token, and the
        // SPA routes to /mfa/verify. /mfa/verify takes the pending_token +
        // a TOTP code and issues the real Sanctum token on success.
        if ($user->mfa_secret) {
            $pending = Str::random(64);
            Cache::put('helm.mfa.pending.' . $pending, $user->id, now()->addMinutes(5));
            return response()->json([
                'mfa_required'  => true,
                'pending_token' => $pending,
            ], 200);
        }

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $token = $user->createToken('helm-spa')->plainTextToken;

        return response()->json([
            'user'  => new UserResource($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();
        return response()->json(null, 204);
    }

    /**
     * POST /api/auth/password/forgot
     *
     * Sends a password reset link via Laravel's Password broker. In dev the
     * mailer is the `log` driver so the URL ends up in storage/logs/laravel.log;
     * in prod this needs SMTP wired (MAIL_MAILER=smtp + creds in .env).
     *
     * Always returns 200 with the same neutral message regardless of whether
     * the email exists — defends against account enumeration.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
        ]);

        // Build the reset URL the email will contain. Frontend handles /reset-password.
        \Illuminate\Support\Facades\Password::createUrlUsing(function (User $user, string $token) {
            $base = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
            return $base . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($user->email);
        });

        $status = \Illuminate\Support\Facades\Password::sendResetLink(['email' => $data['email']]);

        // Audit the attempt (not the token) so we have a record of who tried.
        AuditLog::create([
            'actor_user_id' => null,
            'action'        => 'auth.password.forgot_requested',
            'target_type'   => 'user',
            'target_id'     => null,
            'metadata'      => [
                'email'  => $data['email'],
                'status' => (string) $status,
            ],
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'If an account exists for that email, a reset link is on its way. The link expires in 60 minutes.',
        ]);
    }

    /**
     * POST /api/auth/password/reset
     *
     * Consumes the token from the email and sets a new password. Same min-12
     * rule as changePassword + acceptInvitation.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $status = \Illuminate\Support\Facades\Password::reset(
            $data,
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                AuditLog::create([
                    'actor_user_id' => $user->id,
                    'action'        => 'auth.password.reset',
                    'target_type'   => 'user',
                    'target_id'     => $user->id,
                    'metadata'      => ['email' => $user->email],
                    'ip'            => request()->ip(),
                    'user_agent'    => request()->userAgent(),
                ]);
            }
        );

        if ($status !== \Illuminate\Support\Facades\Password::PASSWORD_RESET) {
            return response()->json([
                'message' => match ($status) {
                    \Illuminate\Support\Facades\Password::INVALID_TOKEN => 'Reset link is invalid or has expired. Request a fresh one.',
                    \Illuminate\Support\Facades\Password::INVALID_USER  => 'No account matches that email.',
                    default => 'Reset failed. Try requesting a new link.',
                },
            ], 422);
        }

        return response()->json([
            'message' => 'Password updated. Sign in with the new password.',
        ]);
    }

    public function me(Request $request): JsonResource
    {
        return new UserResource($request->user());
    }

    /**
     * PATCH /api/auth/me
     * Lets the signed-in user update their own profile and notification prefs.
     */
    public function updateMe(Request $request): JsonResource
    {
        $user = $request->user();
        abort_unless($user, 401);

        $data = $request->validate([
            'name'                => ['sometimes', 'string', 'max:120'],
            'email'               => ['sometimes', 'email', 'max:190', 'unique:users,email,' . $user->id],
            'display_initials'    => ['sometimes', 'nullable', 'string', 'max:4'],
            'timezone'            => ['sometimes', 'string', 'max:64'],
            'notification_prefs'  => ['sometimes', 'array'],
            'notification_prefs.daily_sync_digest' => ['sometimes', 'boolean'],
            'notification_prefs.connection_errored' => ['sometimes', 'boolean'],
            'notification_prefs.ticket_assigned' => ['sometimes', 'boolean'],
            'notification_prefs.weekly_summary' => ['sometimes', 'boolean'],
        ]);

        $user->fill($data)->save();

        return new UserResource($user->fresh());
    }

    /**
     * POST /api/auth/password
     * Body: { current_password, new_password, new_password_confirmation }
     * Validates current password, sets the new hash, and revokes all *other*
     * active tokens (so other devices are signed out — current session keeps
     * working). Writes an audit log entry.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $request->validated();
        $user = $request->user();
        abort_unless($user, 401);

        // The `password` cast on the User model hashes automatically.
        $user->password = $request->input('new_password');
        $user->save();

        // Revoke every token except the one used for this request.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        AuditLog::create([
            'actor_user_id' => $user->id,
            'action'        => 'password.changed',
            'target_type'   => 'user',
            'target_id'     => $user->id,
            'metadata'      => null,
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Password updated. Other devices have been signed out.',
        ]);
    }

    /**
     * POST /api/auth/onboarding
     * Saves the user's profile basics + the workspace name/currency
     * in one atomic step and marks onboarding complete. Partial submits
     * are allowed — fields are optional so a user can revisit any tab to
     * fill in what they skipped.
     */
    public function completeOnboarding(Request $request): JsonResource
    {
        $user = $request->user();
        abort_unless($user, 401);

        // Permissive on purpose — the UI does the strict checks. Anything the
        // user fills gets saved, anything they skipped stays at the existing
        // value. Empty strings are converted to null by Laravel's default
        // middleware, so `nullable` covers blanks.
        $data = $request->validate([
            'name'              => ['sometimes', 'nullable', 'string', 'max:120'],
            'display_initials'  => ['sometimes', 'nullable', 'string', 'max:4'],
            'timezone'          => ['sometimes', 'nullable', 'string', 'max:64'],
            'workspace_name'    => ['sometimes', 'nullable', 'string', 'max:120'],
            'primary_currency'  => ['sometimes', 'nullable', 'string', 'in:USD,EUR,GBP'],
        ]);

        // Filter out nulls so we don't overwrite existing values with blanks.
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');

        $userFields = collect($data)->only(['name', 'display_initials', 'timezone'])->all();
        if (! empty($userFields)) {
            $user->fill($userFields);
        }
        $user->onboarding_completed_at = now();
        $user->save();

        // Only the founding admin sets workspace-level details during onboarding;
        // invited users join an existing workspace and must never overwrite it.
        if ($user->role === 'master_admin') {
            if (isset($data['workspace_name'])) {
                WorkspaceSetting::setValue('workspace_name', $data['workspace_name']);
            }
            if (isset($data['primary_currency'])) {
                WorkspaceSetting::setValue('primary_currency', $data['primary_currency']);
            }
        }

        AuditLog::create([
            'actor_user_id' => $user->id,
            'action'        => 'user.onboarded',
            'target_type'   => 'user',
            'target_id'     => $user->id,
            'metadata'      => $data,
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);

        return new UserResource($user->fresh());
    }

    /**
     * POST /api/auth/avatar
     * Multipart form-data with `avatar` file. Stored on the public disk
     * under `avatars/{user_id}-{random}.{ext}`. Old avatar (if any) is
     * removed after the new one lands so we don't accumulate orphans.
     */
    public function uploadAvatar(Request $request): JsonResource
    {
        // `file` + explicit mimetypes (not `image`) — `image` requires GD/Imagick
        // and rejects HEIC. mimetypes check is content-type based so it works
        // on environments without imaging extensions.
        $request->validate([
            'avatar' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif',
                'max:5120', // 5 MB — iPhone photos can clear 4MB
            ],
        ]);

        $user = $request->user();
        abort_unless($user, 401);

        $oldPath = $user->avatar_path;

        $path = $request->file('avatar')->store("avatars", 'public');
        $user->update(['avatar_path' => $path]);

        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        AuditLog::create([
            'actor_user_id' => $user->id,
            'action'        => 'user.avatar_uploaded',
            'target_type'   => 'user',
            'target_id'     => $user->id,
            'metadata'      => ['path' => $path],
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);

        return new UserResource($user->fresh());
    }

    public function deleteAvatar(Request $request): JsonResource
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }
        $user->update(['avatar_path' => null]);

        return new UserResource($user->fresh());
    }

    /**
     * POST /api/auth/mfa/setup
     *
     * Generates a fresh TOTP secret for the signed-in user (does NOT persist
     * it yet — that happens in mfaVerify once the user proves they can read
     * it from their authenticator app). Returns the secret + an otpauth://
     * URI + an SVG QR code so the SPA can render whichever it prefers.
     *
     * The pending secret is stashed in cache keyed by user_id for 10 minutes.
     */
    public function mfaSetup(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->mfa_secret) {
            return response()->json([
                'message' => 'MFA is already enabled. Disable it first if you want to re-enroll.',
            ], 409);
        }

        $g2fa = new Google2FA();
        $secret = $g2fa->generateSecretKey(32);

        Cache::put('helm.mfa.enroll.' . $user->id, $secret, now()->addMinutes(10));

        $issuer = (string) config('app.name', 'Helm');
        $otpauth = $g2fa->getQRCodeUrl($issuer, $user->email, $secret);

        // Render the QR as inline SVG so the SPA can <img src={dataUri}/> it.
        $renderer = new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd());
        $writer   = new Writer($renderer);
        $svg      = $writer->writeString($otpauth);
        $qrSvg    = 'data:image/svg+xml;base64,' . base64_encode($svg);

        return response()->json([
            'secret'       => $secret,
            'otpauthUrl'   => $otpauth,
            'qrCodeSvg'    => $qrSvg,
            'instructions' => 'Scan this in your authenticator app, then submit the 6-digit code to confirm enrollment.',
        ]);
    }

    /**
     * POST /api/auth/mfa/verify
     *
     * Two modes, distinguished by which token is in the body:
     *
     *  - { code, secret }  → enrollment verification while signed-in. If the
     *    code matches the secret we stashed in mfaSetup, persist it on the
     *    user record (encrypted via the model cast).
     *
     *  - { code, pending_token }  → login challenge. Resolve pending_token to
     *    the user_id, verify code against their stored mfa_secret, issue a
     *    Sanctum token on success.
     */
    public function mfaVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'          => ['required', 'string', 'size:6'],
            'pending_token' => ['sometimes', 'string'],
        ]);

        $g2fa = new Google2FA();
        $code = $data['code'];

        // Login-challenge mode — used by the MfaVerifyPage after a 200-with-
        // pending_token from /auth/login.
        if (! empty($data['pending_token'])) {
            $userId = Cache::pull('helm.mfa.pending.' . $data['pending_token']);
            if (! $userId) {
                return response()->json([
                    'message' => 'Sign-in session expired. Start over from the login page.',
                ], 410);
            }
            $user = User::query()->find($userId);
            if (! $user || ! $user->mfa_secret) {
                return response()->json(['message' => 'MFA is not configured for this account.'], 422);
            }
            if (! $g2fa->verifyKey($user->mfa_secret, $code, 1)) {
                return response()->json(['message' => 'That code didn’t match. Try the next one your app shows.'], 422);
            }

            $user->update([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ]);

            AuditLog::create([
                'actor_user_id' => $user->id,
                'action'        => 'auth.mfa.verified',
                'target_type'   => 'user',
                'target_id'     => $user->id,
                'metadata'      => null,
                'ip'            => $request->ip(),
                'user_agent'    => $request->userAgent(),
            ]);

            $token = $user->createToken('helm-spa')->plainTextToken;
            return response()->json([
                'user'  => new UserResource($user),
                'token' => $token,
            ]);
        }

        // Enrollment mode — verifies the pending secret stashed by /mfa/setup.
        $user = $request->user();
        abort_unless($user, 401);

        $pendingSecret = Cache::get('helm.mfa.enroll.' . $user->id);
        if (! $pendingSecret) {
            return response()->json([
                'message' => 'Your enrollment secret expired. Restart MFA setup.',
            ], 410);
        }
        if (! $g2fa->verifyKey($pendingSecret, $code, 1)) {
            return response()->json([
                'message' => 'That code didn’t match. Try the next one your app shows.',
            ], 422);
        }

        $user->update(['mfa_secret' => $pendingSecret]);
        Cache::forget('helm.mfa.enroll.' . $user->id);

        AuditLog::create([
            'actor_user_id' => $user->id,
            'action'        => 'auth.mfa.enabled',
            'target_type'   => 'user',
            'target_id'     => $user->id,
            'metadata'      => null,
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);

        return response()->json([
            'enabled' => true,
            'user'    => new UserResource($user->fresh()),
        ]);
    }

    /**
     * POST /api/auth/mfa/disable
     *
     * Clears mfa_secret. Requires the current password so a stolen session
     * token can't be used to turn MFA off.
     */
    public function mfaDisable(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $data = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        if (! $user->mfa_secret) {
            return response()->json(['message' => 'MFA is not enabled.'], 409);
        }

        $user->update(['mfa_secret' => null]);

        AuditLog::create([
            'actor_user_id' => $user->id,
            'action'        => 'auth.mfa.disabled',
            'target_type'   => 'user',
            'target_id'     => $user->id,
            'metadata'      => null,
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);

        return response()->json([
            'enabled' => false,
            'user'    => new UserResource($user->fresh()),
        ]);
    }

    /**
     * GET /api/auth/invitations/preview?token=...
     *
     * Public — no auth required since the recipient doesn't have an account
     * yet. Returns minimal context (email, role, inviter) so the accept page
     * can render meaningful copy before the user picks a password. 404 on
     * any reason the invitation can't be redeemed (missing/expired/revoked/
     * already accepted) so the SPA never has to interpret status codes.
     */
    public function previewInvitation(Request $request): JsonResponse
    {
        $token = (string) $request->query('token', '');
        if ($token === '') {
            return response()->json(['message' => 'Missing invitation token.'], 404);
        }

        $invitation = \App\Models\Invitation::query()
            ->with('invitedBy:id,name,email')
            ->where('token', $token)
            ->first();

        if (! $invitation || ! $invitation->isPending()) {
            $reason = match (true) {
                ! $invitation                            => 'Invitation not found.',
                $invitation->accepted_at !== null        => 'This invitation has already been accepted.',
                $invitation->revoked_at !== null         => 'This invitation has been revoked.',
                $invitation->expires_at?->isPast()       => 'This invitation has expired. Ask the inviter to send a new one.',
                default                                  => 'This invitation can no longer be redeemed.',
            };
            return response()->json(['message' => $reason], 404);
        }

        return response()->json([
            'email'     => $invitation->email,
            'role'      => $invitation->role,
            'invitedBy' => $invitation->invitedBy ? [
                'name'  => $invitation->invitedBy->name,
                'email' => $invitation->invitedBy->email,
            ] : null,
            'expiresAt' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    /**
     * POST /api/auth/invitations/accept
     *
     * Body: { token, name, password, password_confirmation }
     * Creates the user record, marks the invitation accepted, returns a
     * Sanctum bearer token so the SPA can drop the user straight into /onboarding.
     */
    public function acceptInvitation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => ['required', 'string'],
            'name'     => ['required', 'string', 'max:120'],
            // Min 12 to match the UI hint + ChangePasswordRequest.
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        // Wrap the whole flow in a transaction with a row lock on the
        // invitation. Without this, two concurrent accepts on the same token
        // both pass the accepted_at check before either commits and we end
        // up with two user rows.
        try {
            $result = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $request) {
                $invitation = \App\Models\Invitation::query()
                    ->where('token', $data['token'])
                    ->lockForUpdate()
                    ->first();

                if (! $invitation) {
                    return ['status' => 404, 'message' => 'Invitation not found.'];
                }
                if ($invitation->accepted_at !== null) {
                    return ['status' => 409, 'message' => 'This invitation has already been accepted.'];
                }
                if ($invitation->revoked_at !== null) {
                    return ['status' => 410, 'message' => 'This invitation has been revoked.'];
                }
                if ($invitation->expires_at?->isPast()) {
                    return ['status' => 410, 'message' => 'This invitation has expired. Ask your admin to send a new one.'];
                }

                if (\App\Models\User::query()->where('email', $invitation->email)->exists()) {
                    return [
                        'status'  => 409,
                        'message' => 'An account with this email already exists. Try signing in instead.',
                    ];
                }

                $user = \App\Models\User::create([
                    'name'     => $data['name'],
                    'email'    => $invitation->email,
                    'password' => $data['password'],
                    'role'     => $invitation->role,
                    'status'   => 'active',
                ]);

                if (! empty($invitation->brand_ids) && is_array($invitation->brand_ids)) {
                    $payload = [];
                    foreach ($invitation->brand_ids as $bid) {
                        $payload[(int) $bid] = ['granted_by_user_id' => $invitation->invited_by_user_id];
                    }
                    $user->accessibleBrands()->sync($payload);
                }

                $invitation->update([
                    'accepted_at'         => now(),
                    'accepted_by_user_id' => $user->id,
                ]);

                \App\Models\AuditLog::create([
                    'actor_user_id' => $user->id,
                    'action'        => 'invitation.accepted',
                    'target_type'   => 'invitation',
                    'target_id'     => $invitation->id,
                    'metadata'      => ['email' => $invitation->email, 'role' => $invitation->role],
                    'ip'            => $request->ip(),
                    'user_agent'    => $request->userAgent(),
                ]);

                return ['status' => 201, 'user' => $user];
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // 23505 = unique violation — concurrent signup with same email.
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'An account with this email already exists. Try signing in instead.',
                ], 409);
            }
            throw $e;
        }

        if ($result['status'] !== 201) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        $user = $result['user'];

        // Audit row was already written inside the transaction.
        $token = $user->createToken('helm-web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => new UserResource($user),
        ], 201);
    }
}
