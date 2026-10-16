<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Http\UploadFile;
use FullStackStudio\Content\Internal\ModuleContext;

/**
 * Write routes and server-only read routes (`$client->admin`). Needs a server token (`fsc_srv_...`) with the right scopes.
 * Every method takes its call options as the last argument; bodies are plain arrays shaped like the API (docs/api-v1.md).
 */
final class Admin
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    // --- Articles --------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> the article */
    public function createArticle(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/articles', $input, $options)['article'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array{article: array<string, mixed>, changed?: mixed} */
    public function updateArticle(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/articles/' . ModuleContext::seg($id), $input, $options);
    }

    /** @param array<string, mixed> $options @return array{article: array<string, mixed>, changed?: mixed} */
    public function publishArticle(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/articles/' . ModuleContext::seg($id) . '/publish', null, $options);
    }

    /** Draft to `in_review`. Approving is not possible with an API token (FSS portal or MCP only). @param array<string, mixed> $options @return array<string, mixed> */
    public function submitArticleForReview(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/articles/' . ModuleContext::seg($id) . '/submit-for-review', null, $options);
    }

    /**
     * Copy as a draft in another language (same translation group).
     *
     * @param 'articles'|'pages'|'products'|'categories' $kind
     * @param array{locale: string, slug?: string} $input
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function createTranslation(string $kind, string $id, array $input, array $options = []): array
    {
        $path = match ($kind) {
            'articles', 'pages', 'products', 'categories' => "/{$kind}/" . ModuleContext::seg($id) . '/translations',
            default => throw new ContentException(0, 'invalid_config', "Unknown translation kind: {$kind}"),
        };

        return $this->ctx->write('POST', $path, $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function unpublishArticle(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/articles/' . ModuleContext::seg($id) . '/unpublish', null, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function deleteArticle(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/articles/' . ModuleContext::seg($id), null, $options);
    }

    // --- Categories ------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $input `name` is required @param array<string, mixed> $options @return array<string, mixed> the category */
    public function createCategory(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/categories', $input, $options)['category'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function updateCategory(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/categories/' . ModuleContext::seg($id), $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function deleteCategory(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/categories/' . ModuleContext::seg($id), null, $options);
    }

    // --- Pages -----------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $input `title` is required @param array<string, mixed> $options @return array<string, mixed> the page */
    public function createPage(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/pages', $input, $options)['page'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function updatePage(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/pages/' . ModuleContext::seg($id), $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function publishPage(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/pages/' . ModuleContext::seg($id) . '/publish', null, $options);
    }

    /** Draft to `in_review`. @param array<string, mixed> $options @return array<string, mixed> */
    public function submitPageForReview(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/pages/' . ModuleContext::seg($id) . '/submit-for-review', null, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function unpublishPage(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/pages/' . ModuleContext::seg($id) . '/unpublish', null, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function deletePage(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/pages/' . ModuleContext::seg($id), null, $options);
    }

    /** The last 20 revisions, newest first, without blocks. @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function listPageRevisions(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/pages/' . ModuleContext::seg($id) . '/revisions', 'options' => $options])->data['revisions'] ?? [];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function getPageRevision(string $id, int $revision, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/pages/' . ModuleContext::seg($id) . '/revisions/' . ModuleContext::seg($revision), 'options' => $options])->data['revision'] ?? [];
    }

    /** Restores title, blocks, SEO and cover; path, locale and status stay as they are. @param array<string, mixed> $options @return array<string, mixed> */
    public function restorePageRevision(string $id, int $revision, array $options = []): array
    {
        return $this->ctx->write('POST', '/pages/' . ModuleContext::seg($id) . '/revisions/' . ModuleContext::seg($revision) . '/restore', null, $options);
    }

    // --- Redirects and SEO -----------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> the redirect */
    public function createRedirect(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/redirects', $input, $options)['redirect'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function updateRedirect(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/redirects/' . ModuleContext::seg($id), $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function deleteRedirect(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/redirects/' . ModuleContext::seg($id), null, $options);
    }

    /** Up to 500 redirects, all or nothing. @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function bulkRedirects(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/redirects/bulk', $input, $options);
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array{seo: array<string, mixed>, changed: list<string>} */
    public function updateSeo(array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/seo', $input, $options);
    }

    // --- Media -----------------------------------------------------------------------------------------------------------

    /**
     * Uploads a file. `$input` takes the file as `path` (a readable file) or `contents` (the bytes), plus optional `filename`,
     * `contentType` and `alt`.
     *
     * @param array{path?: string, contents?: string, filename?: string, contentType?: string, alt?: string} $input
     * @param array<string, mixed> $options
     * @return array<string, mixed> the media item
     */
    public function uploadMedia(array $input, array $options = []): array
    {
        if (isset($input['path'])) {
            $contents = @file_get_contents($input['path']);

            if ($contents === false) {
                throw new ContentException(0, 'invalid_config', 'uploadMedia: the file at `path` could not be read.');
            }

            $filename = $input['filename'] ?? basename($input['path']);
        } elseif (isset($input['contents'])) {
            $contents = $input['contents'];
            $filename = $input['filename'] ?? 'file';
        } else {
            throw new ContentException(0, 'invalid_config', 'uploadMedia: pass `path` or `contents`.');
        }

        $form = ['file' => new UploadFile($contents, $filename, $input['contentType'] ?? 'application/octet-stream')];

        if (isset($input['alt'])) {
            $form['alt'] = $input['alt'];
        }

        return $this->ctx->execute(['method' => 'POST', 'path' => '/media', 'form' => $form, 'options' => $options])->data['media'] ?? [];
    }

    /** Sets the alt text of a file (an empty string clears it). Scope `media:write`. @param array<string, mixed> $options @return array<string, mixed> */
    public function updateMediaAlt(string $id, string $alt, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/media/' . ModuleContext::seg($id), ['alt' => $alt], $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function deleteMedia(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/media/' . ModuleContext::seg($id), null, $options);
    }

    // --- Submissions (personal data, server token only) ------------------------------------------------------------------

    /**
     * @param array{status?: string, page?: int, limit?: int} $params
     * @param array<string, mixed> $options
     * @return array{submissions: list<array<string, mixed>>, page: int, limit: int, total: int}
     */
    public function listSubmissions(string $formKey, array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute([
            'path' => '/forms/' . ModuleContext::seg($formKey) . '/submissions',
            'query' => ['status' => $params['status'] ?? null, 'page' => $params['page'] ?? null, 'limit' => $params['limit'] ?? null],
            'options' => $options,
        ]));
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function getSubmission(string $formKey, string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/forms/' . ModuleContext::seg($formKey) . '/submissions/' . ModuleContext::seg($id), 'options' => $options])->data['submission'] ?? [];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function setSubmissionStatus(string $formKey, string $id, string $status, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/forms/' . ModuleContext::seg($formKey) . '/submissions/' . ModuleContext::seg($id), ['status' => $status], $options);
    }

    // --- Webhooks --------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function listWebhooks(array $options = []): array
    {
        return $this->ctx->execute(['path' => '/webhooks', 'options' => $options])->data['webhooks'] ?? [];
    }

    /** The `secret` appears only in this response: store it right away (`FSC_WEBHOOK_SECRET`). @param array<string, mixed> $input @param array<string, mixed> $options @return array{webhook: array<string, mixed>, secret: string} */
    public function createWebhook(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/webhooks', $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function getWebhook(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/webhooks/' . ModuleContext::seg($id), 'options' => $options])->data['webhook'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function updateWebhook(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/webhooks/' . ModuleContext::seg($id), $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function deleteWebhook(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/webhooks/' . ModuleContext::seg($id), null, $options);
    }

    /** Queues a test delivery (visible in `listWebhookDeliveries` within ~15 s). @param array<string, mixed> $options @return array{queued: true, deliveryId?: string} */
    public function testWebhook(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/webhooks/' . ModuleContext::seg($id) . '/test', null, $options);
    }

    /** @param array<string, mixed> $options @return array{webhook: array<string, mixed>, secret: string} */
    public function rotateWebhookSecret(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/webhooks/' . ModuleContext::seg($id) . '/rotate-secret', null, $options);
    }

    /** @param array{limit?: int} $params @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function listWebhookDeliveries(string $id, array $params = [], array $options = []): array
    {
        return $this->ctx->execute([
            'path' => '/webhooks/' . ModuleContext::seg($id) . '/deliveries',
            'query' => ['limit' => $params['limit'] ?? null],
            'options' => $options,
        ])->data['deliveries'] ?? [];
    }

    // --- Payments --------------------------------------------------------------------------------------------------------

    /**
     * Starts (or reuses an open) Mollie payment for an appointment or a form submission (module `payments`, scope `payments:write`).
     * The amount comes from Content, never from you. Send the visitor to `payment.checkoutUrl`.
     *
     * @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed>
     */
    public function startPayment(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/payments', $input, $options);
    }
}
