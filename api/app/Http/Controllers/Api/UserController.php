<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InviteUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 1.5 user-management endpoints. All actions go through the
 * AuditLog so the Audit log page surfaces who did what when.
 */
class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()->orderBy('name')->limit(200)->get();
        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);
        return new UserResource($user);
    }

    public function invite(InviteUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Refuse to invite an email that already has an active user — that's
        // not an invitation, it's a re-assignment, and we want that to be an
        // explicit edit not a silent overwrite.
        $existing = User::query()->where('email', $data['email'])->first();
        if ($existing) {
            return response()->json([
                'message' => "{$data['email']} already has a Helm account. Edit their profile instead.",
            ], 409);
        }

        // Same defense for an outstanding pending invite. The operator can
        // revoke the old one first, then send a new one.
        $pending = Invitation::query()
            ->where('email', $data['email'])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
        if ($pending) {
            return response()->json([
                'message' => "{$data['email']} already has a pending invitation. Revoke it first or wait for it to expire.",
            ], 409);
        }

        $invitation = Invitation::create([
            'email'              => $data['email'],
            'role'               => $data['role'],
            'token'              => Str::random(48),
            'note'               => $data['note'] ?? null,
            'brand_ids'          => $data['brand_ids'] ?? null,
            'invited_by_user_id' => $request->user()?->id,
            'expires_at'         => now()->addDays(7),
        ]);

        AuditLog::create([
            'actor_user_id' => $request->user()?->id,
            'action'        => 'invitation.sent',
            'target_type'   => 'invitation',
            'target_id'     => $invitation->id,
            'metadata'      => ['email' => $invitation->email, 'role' => $invitation->role],
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);

        // Email delivery is a Phase-1.5 OQ — for now we log the accept URL so
        // the agency owner can paste it into a manual email. When the SMTP
        // provider lands this gets replaced with Mail::send().
        $acceptUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/')
            . '/accept-invite?token=' . $invitation->token;
        Log::info('Invitation issued (manual email until SMTP provider lands)', [
            'email'      => $invitation->email,
            'accept_url' => $acceptUrl,
        ]);

        return response()->json([
            'invitation' => $this->serialiseInvitation($invitation),
            'acceptUrl'  => $acceptUrl,
        ], 201);
    }

    public function listInvitations(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $rows = Invitation::query()
            ->with(['invitedBy:id,name,email'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Invitation $i) => $this->serialiseInvitation($i))->all(),
        ]);
    }

    public function revokeInvitation(Request $request, int $id): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $invitation = Invitation::query()->findOrFail($id);

        if ($invitation->accepted_at !== null) {
            return response()->json([
                'message' => 'This invitation was already accepted and can no longer be revoked.',
            ], 409);
        }

        $invitation->update(['revoked_at' => now()]);

        AuditLog::create([
            'actor_user_id' => $request->user()?->id,
            'action'        => 'invitation.revoked',
            'target_type'   => 'invitation',
            'target_id'     => $invitation->id,
            'metadata'      => ['email' => $invitation->email],
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);

        return response()->json(null, 204);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $patch = $request->validated();

        $brandIds = $patch['brand_ids'] ?? null;
        unset($patch['brand_ids']);

        // Lock the row inside a transaction so the master-admin demote check
        // is consistent — without the lock, two concurrent admins could each
        // pass the role guard against a stale in-memory copy.
        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $patch, $brandIds, $request) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($locked->role === 'master_admin' && isset($patch['role']) && $patch['role'] !== 'master_admin') {
                $actor = $request->user();
                if (! $actor || $actor->role !== 'master_admin' || $actor->id === $locked->id) {
                    abort(403, 'Only another master admin can demote a master admin.');
                }
            }

            $locked->update($patch);

            if (is_array($brandIds)) {
                $payload = [];
                foreach ($brandIds as $bid) {
                    $payload[(int) $bid] = ['granted_by_user_id' => $request->user()?->id];
                }
                $locked->accessibleBrands()->sync($payload);
            }
        });

        AuditLog::create([
            'actor_user_id' => $request->user()?->id,
            'action'        => 'user.updated',
            'target_type'   => 'user',
            'target_id'     => $user->id,
            'metadata'      => array_merge(
                ['fields' => array_keys($patch)],
                is_array($brandIds) ? ['brand_ids' => $brandIds] : []
            ),
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);

        return new UserResource($user->fresh());
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('disable', $user);

        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $request) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($locked->role === 'master_admin') {
                abort(403, "Master admins can't be disabled. Demote them first.");
            }
            if ($request->user()?->id === $locked->id) {
                abort(403, "You can't disable your own account.");
            }

            $locked->update(['status' => 'disabled']);

            AuditLog::create([
                'actor_user_id' => $request->user()?->id,
                'action'        => 'user.disabled',
                'target_type'   => 'user',
                'target_id'     => $locked->id,
                'metadata'      => ['email' => $locked->email],
                'ip'            => $request->ip(),
                'user_agent'    => $request->userAgent(),
            ]);
        });

        return response()->json(null, 204);
    }

    /**
     * DELETE /api/users/{user}/permanent — hard-delete a DISABLED user.
     *
     * Deliberately a two-step flow: a user must be disabled first (destroy()),
     * then permanently removed here. Same guards as disabling — never a master
     * admin, never yourself. FKs do the cleanup: brand_user_access cascades;
     * audit_logs / invitations / credentials / report_shares null their actor
     * refs (see migrations), so the audit trail survives with a null actor. The
     * `user.deleted` entry we write keeps the email for the record.
     */
    public function forceDelete(Request $request, User $user): JsonResponse
    {
        $this->authorize('disable', $user);

        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $request) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($locked->role === 'master_admin') {
                abort(403, "Master admins can't be deleted. Demote them first.");
            }
            if ($request->user()?->id === $locked->id) {
                abort(403, "You can't delete your own account.");
            }
            if ($locked->status !== 'disabled') {
                abort(409, 'Disable the user before deleting them permanently.');
            }

            $email = $locked->email;
            $id    = $locked->id;

            // Prune login sessions (indexed by user_id, no FK), then hard-delete.
            \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $id)->delete();
            $locked->delete();

            AuditLog::create([
                'actor_user_id' => $request->user()?->id,
                'action'        => 'user.deleted',
                'target_type'   => 'user',
                'target_id'     => $id,
                'metadata'      => ['email' => $email, 'permanent' => true],
                'ip'            => $request->ip(),
                'user_agent'    => $request->userAgent(),
            ]);
        });

        return response()->json(null, 204);
    }

    /**
     * Shape an Invitation for the API. Centralised so list + create return the
     * same fields. Status is computed (not stored) — see Invitation::status().
     *
     * @return array<string, mixed>
     */
    private function serialiseInvitation(Invitation $i): array
    {
        return [
            'id'         => $i->id,
            'email'      => $i->email,
            'role'       => $i->role,
            'note'       => $i->note,
            'brandIds'   => $i->brand_ids ?? [],
            'status'     => $i->status(),
            'invitedBy'  => $i->invitedBy ? [
                'id'    => $i->invitedBy->id,
                'name'  => $i->invitedBy->name,
                'email' => $i->invitedBy->email,
            ] : null,
            'expiresAt'  => $i->expires_at?->toIso8601String(),
            'acceptedAt' => $i->accepted_at?->toIso8601String(),
            'revokedAt'  => $i->revoked_at?->toIso8601String(),
            'createdAt'  => $i->created_at?->toIso8601String(),
        ];
    }
}
