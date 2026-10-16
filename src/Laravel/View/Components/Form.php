<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Forms;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Component;
use Throwable;

/**
 * `<x-content::form form-key="contact" />`: a server-rendered form built from the form definition in Content. It works without JavaScript:
 * it POSTs to the package's form route (opt-in: `Content::formRoutes()` or `FSC_FORMS_ENABLED=true`), which validates, submits to Content with
 * the visitor's IP and redirects back with a flash message, the old input and the errors per field.
 *
 * Needs the `web` middleware group around the page (session and CSRF). Turnstile (when the form has a site key) is rendered as the usual widget and needs
 * JavaScript; without it the submit is refused with a clear message.
 */
final class Form extends Component
{
    /** @var array<string, mixed>|null */
    public ?array $definition = null;

    /** @var array<string, string> */
    public array $labels;

    public string $action;

    public string $bag;

    public string $anchor;

    /** @var array{status?: string, message?: string}|null */
    public ?array $flash = null;

    public ?string $loadError = null;

    /** @var array<string, string> */
    public array $fieldErrors = [];

    /**
     * @param array<string, string> $labels overrides for individual texts (wins over `locale`)
     * @param array<string, mixed>|null $definition a form definition you fetched yourself (saves a request)
     */
    public function __construct(public readonly string $formKey, ?string $locale = null, array $labels = [], ?string $action = null, ?array $definition = null)
    {
        $this->labels = Forms::labels($locale ?? config('content.forms.locale') ?? app()->getLocale(), $labels);
        $this->bag = 'content_form_' . $formKey;
        $this->anchor = 'fsc-form-' . $formKey;
        $this->action = $action ?? (Route::has('content.form') ? route('content.form', ['key' => $formKey]) : url(trim((string) config('content.forms.path', 'content/forms'), '/') . '/' . rawurlencode($formKey)));

        if ($definition === null) {
            try {
                $definition = app(ContentClient::class)->getForm($formKey);
            } catch (Throwable) {
                $this->loadError = $this->labels['loadError'];
            }
        }

        $this->definition = $definition ?: null;

        if ($this->definition === null && $this->loadError === null) {
            $this->loadError = $this->labels['loadError'];
        }

        $this->flash = self::session('content_form.' . $formKey);
        $errors = self::session('errors');
        $messages = $errors instanceof ViewErrorBag && $errors->hasBag($this->bag) ? $errors->getBag($this->bag)->toArray() : [];
        $this->fieldErrors = array_map(static fn (array $list): string => (string) ($list[0] ?? ''), $messages);
    }

    /** @return mixed */
    private static function session(string $key): mixed
    {
        try {
            return app('session.store')->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    public function render(): View
    {
        return view('content::components.form');
    }
}
