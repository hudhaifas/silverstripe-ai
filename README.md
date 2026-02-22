# silverstripe-ai

Add a context-aware AI agent/chatbot to any SilverStripe 5 site in minutes.

The agent knows which DataObject the user is looking at, can call tools to read or write data, asks for confirmation before making changes (Human-in-the-Loop), and tracks every token and dollar spent per member.

```bash
composer require hudhaifas/silverstripe-ai
```

---

## What you get out of the box

- **REST API** — two endpoints (`/api/agent/chat`, `/api/agent/resume`) wired and ready
- **Context-aware agent** — pass any DataObject; the agent gets it as context automatically
- **Human-in-the-Loop (HITL)** — write tools pause and ask the user to approve before executing
- **Zero-dependency storage** — conversation history and workflow state use SilverStripe's built-in cache (filesystem/APCu) by default; swap to Redis with one YAML line
- **Multi-provider** — OpenAI and Anthropic (with prompt caching for up to 90% cost reduction)
- **Credit system** — free monthly credits + purchasable credits per member, with automatic model tier selection
- **Usage logging** — every request logged with token counts, cost, and cache metrics
- **Admin UI** — manage models and review usage logs from the CMS

---

## How it works

```
Browser → POST /api/agent/chat
            ↓
       AgentController
            ↓
       AgentService  ←→  MemberAIExtension (credit check + model selection)
            ↓
       AgentFactory  →  YourCustomAgent (extends DataObjectAgent)
            ↓
       NeuronAI  ←→  OpenAI / Anthropic
            ↓
       [tool called that writes data]
            ↓
       WorkflowInterrupt → returns resumeToken to browser
            ↓
       User approves/rejects in UI
            ↓
       POST /api/agent/resume → tool executes → LLM responds
```

---

## Quick start

### 1. Install

```bash
composer require hudhaifas/silverstripe-ai
vendor/bin/sake dev/build flush=1
```

Set your API keys in `.env`:

```dotenv
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
```

### 2. Configure default models in the CMS

Go to Settings → AI and pick a free-tier model (e.g. `gpt-4o-mini`) and a paid-tier model (e.g. `claude-sonnet-4-5`). Models are seeded automatically on `dev/build`.

### 3. Create your agent

```php
use Hudhaifas\AI\Agent\DataObjectAgent;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;

class ProductAgent extends DataObjectAgent
{
    // Cached by Anthropic for 5 min — put stable rules and tool descriptions here.
    // For OpenAI this is just the first part of the system prompt.
    protected function getStaticInstructions(): string
    {
        return 'You are a helpful assistant for managing products.
                Use the available tools to read and update product data.
                Always confirm with the user before making changes.';
    }

    // Injected fresh on every request — current entity state, date, session info.
    // Not cached, so keep it concise.
    protected function getDynamicContext(): string
    {
        $product = $this->contextEntity; // the DataObject passed from the controller
        return "Current product: {$product->Title} (ID: {$product->ID})\n"
             . "Price: {$product->Price}\n"
             . "Stock: {$product->Stock}";
    }

    // All tools the LLM can call. Read-only tools run immediately;
    // write tools are gated by ToolApproval in middleware() below.
    public function tools(): array
    {
        return [
            new GetProductDetailsTool(),   // read — runs freely
            new UpdateProductPriceTool(),  // write — requires user approval
            new DeleteProductTool(),       // write — requires user approval
        ];
    }

    // ToolApproval intercepts the listed tools before execution and returns
    // a resume_token to the frontend. The agent pauses until the user
    // approves or rejects via POST /api/agent/resume.
    protected function middleware(): array
    {
        return [
            ToolNode::class => [
                new ToolApproval(tools: [
                    UpdateProductPriceTool::class,
                    DeleteProductTool::class,
                ]),
            ],
        ];
    }
}
```

### 4. Register the mapping

```yaml
# _config/agent.yml
Hudhaifas\AI\Factory\AgentFactory:
  agent_mappings:
    MyApp\Model\Product: 'MyApp\Agent\ProductAgent'
```

### 5. Add the sidebar to your page

```yaml
# _config/extensions.yml
MyApp\Controller\ProductPageController:
  extensions:
    - Hudhaifas\AI\Extension\AgentPageExtension
```

Include `$AgentWidget` in your template and the JS/CSS loads automatically.

### 6. Apply member extensions

```yaml
SilverStripe\Security\Member:
  extensions:
    - Hudhaifas\AI\Extension\MemberAIExtension

SilverStripe\SiteConfig\SiteConfig:
  extensions:
    - Hudhaifas\AI\Extension\SiteConfigAIExtension
```

---

## Human-in-the-Loop (HITL)

HITL is driven by `ToolApproval` middleware attached to `ToolNode::class`. No code is needed inside the tool itself — the interrupt is automatic.

When the LLM calls a tool listed in `ToolApproval`, the agent pauses before executing it and returns a `resume_token` plus a description of the pending action to the caller. The frontend shows a confirmation card. The user approves or rejects. A POST to `/api/agent/resume` continues the workflow.

Override `middleware()` in your agent subclass to declare which tools require approval:

```php
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;

protected function middleware(): array
{
    return [
        ToolNode::class => [
            new ToolApproval(tools: [
                UpdateProductPriceTool::class, // pauses — user must approve
                DeleteProductTool::class,      // pauses — user must approve
            ]),
        ],
    ];
}
```

Read-only tools not listed here execute immediately without interruption.

The agent's `summariseAction()` method generates the human-readable description shown in the confirmation card. Override it in your subclass to customise the message per tool.

---

## Storage backends

Out of the box, conversation history and HITL workflow state are stored in SilverStripe's built-in cache pool (`silverstripe-cache/`). This works on any shared hosting or server without Redis.

The cache pool is wired automatically — nothing to configure.

### Optional: Redis

If you want persistent history that survives PHP-FPM restarts, or you're running multiple web nodes, swap to Redis with two YAML lines:

```yaml
# _config/agent.yml
SilverStripe\Core\Injector\Injector:
  NeuronAI\Chat\History\ChatHistoryInterface:
    class: Hudhaifas\AI\Chat\RedisChatHistory

  NeuronAI\Workflow\Persistence\PersistenceInterface:
    class: Hudhaifas\AI\Workflow\RedisPersistence
```

`RedisChatHistory` and `RedisPersistence` require `hudhaifas/silverstripe-cache-helpers` (which provides the Redis connection).

### Custom backend

Both backends are resolved through the SilverStripe Injector, so you can bind any implementation:

```yaml
SilverStripe\Core\Injector\Injector:
  NeuronAI\Chat\History\ChatHistoryInterface:
    class: MyApp\Agent\DatabaseChatHistory
```

Or override `createChatHistory()` / `createPersistence()` directly in your agent subclass for per-agent control.

---

## Credit system

Each member gets a configurable free monthly credit allowance (default `$2.00`). When free credits run out, the agent automatically falls back to the free-tier model. Members can purchase additional credits to unlock the paid-tier model.

Admins can override the model per-member and top up credits from the CMS member record.

```php
// Check remaining credits
$member->getTotalCreditsAvailable();  // float

// Add purchased credits
$member->addPurchasedCredits(5.00);

// Refill monthly free credits (call from a scheduled task)
$member->refillFreeCredits();
```

---

## Prompt caching (Anthropic)

When using an Anthropic model, the static part of your system prompt (`getStaticInstructions()`) is automatically cached. On repeated requests within 5 minutes, cache hits cost ~90% less than regular input tokens. No configuration needed.

---

## Environment variables

| Variable | Description | Default |
|---|---|---|
| `OPENAI_API_KEY` | OpenAI API key | — |
| `ANTHROPIC_API_KEY` | Anthropic API key | — |
| `AI_VERBOSE_LOGGING` | Log full NeuronAI traces | `true` |

---

## Configuration reference

### Session locking

By default the controller does **not** call `session_write_close()` before dispatching to the agent. If you use PHP file-based sessions and notice other browser tabs blocking during long agent calls, enable this:

```yaml
Hudhaifas\AI\Controller\AgentController:
  close_session_before_agent: true
```

Not needed when using database or Redis sessions.

---

## Requirements

- PHP 8.1+
- SilverStripe Framework 5.x
- neuron-core/neuron-ai 3.x

**Optional:**
- `hudhaifas/silverstripe-cache-helpers` — required only if using the Redis storage backend

---

## License

BSD-3-Clause
