# LLM setup — add a key and it works

The intelligence layer (D-016, ratified 2026-07-10) ships fully wired. The
ONLY missing ingredient is an API key. Two ways to add it:

## Option A — Settings UI (recommended, survives deploys)

1. Helm → Settings → Platform keys → **AI / LLM**.
2. Paste your **Anthropic API key** (default provider) — or an OpenAI key if
   you set `HELM_LLM_PROVIDER=openai` in `api/.env` first.
3. Click **Test** on the AI / LLM row — it runs a live one-token completion.

## Option B — .env

```bash
# api/.env
HELM_LLM_PROVIDER=anthropic        # or: openai
HELM_ANTHROPIC_API_KEY=sk-ant-...  # or: HELM_OPENAI_API_KEY=sk-...
```

then `php artisan config:cache`.

## Proof step

```bash
php artisan llm:diagnose
```

Prints the provider, model, whether a key is on file, and runs a live ping.
Green = narrative + chat are on. Any error message is the actual provider
error (bad key, wrong model id, billing) — fix and rerun.

## What turns on

- **Report narrative** — open any brand report → "Generate with AI" drafts
  the four blocks (observations, actionable outputs, action plan, new
  ideas). Every block is editable; edits save automatically and are what a
  share link/PDF shows. Regenerate resets edits (a fresh draft is a fresh
  review). Admin/manager only — every generation spends tokens.
- **Ask the data** — brand page → Quick links → "Ask the data (AI)": chat
  with one brand's aggregates for a chosen window. Conversations are not
  stored server-side.

## Guarantees (D-016)

- Rules own every number. The LLM writes prose; the report's figures come
  from the rules engines regardless of what the model says.
- Aggregates only. `api/app/Services/Llm/BrandDataScope.php` is the single
  payload builder for both surfaces — brand name, dated aggregate metrics,
  campaign/product/country names. No customer data (none exists in Helm's
  schema), no tokens, no other brands. Its shape is locked by a CI test.
- Nothing automatic. Generation is always an operator click.

## Model overrides

Defaults: Anthropic `claude-sonnet-4-20250514`, OpenAI `gpt-4o`. Newer
models are an .env change (`HELM_LLM_MODEL_ANTHROPIC` /
`HELM_LLM_MODEL_OPENAI`) + `php artisan config:cache` — verify with
`php artisan llm:diagnose`. Narrative language: pass `language: es` per
generation (API supports `en`/`es`; UI default is English).

## Cost profile

One narrative generation ≈ one prompt of a few KB of aggregates + a ~600
token draft. One chat turn is similar. At 80 brands × one report narrative
per month, token spend is trivial; the admin/manager gate and the
click-to-generate design keep it that way.
