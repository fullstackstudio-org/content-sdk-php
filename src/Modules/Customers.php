<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/** Your customers' customer file (`$client->customers`; module `customers`, server token with `customers:read` / `customers:write`). */
final class Customers
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @param array{q?: string, tag?: string, kind?: string, page?: int, limit?: int} $params @param array<string, mixed> $options @return array<string, mixed> `customers`, `page`, `limit`, `total` */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute(['path' => '/customers', 'query' => Arr::pick($params, ['q', 'tag', 'kind', 'page', 'limit']), 'options' => $options]));
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function get(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/customers/' . ModuleContext::seg($id), 'options' => $options])->data['customer'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function create(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/customers', $input, $options)['customer'] ?? [];
    }

    /** Only the named keys change; `null` clears a field; `address` changes only the named parts (`address => null` clears all). @param array<string, mixed> $patch @param array<string, mixed> $options @return array{customer: array<string, mixed>, changed: bool} */
    public function update(string $id, array $patch, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/customers/' . ModuleContext::seg($id), $patch, $options);
    }

    /** Linked members are unlinked and keep existing. @param array<string, mixed> $options @return array{deleted: true, id: string, unlinkedMembers: int} */
    public function delete(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/customers/' . ModuleContext::seg($id), null, $options);
    }

    /** Internal notes: newest first, at most 100. @param array<string, mixed> $options @return array{notes: list<array<string, mixed>>, total: int} */
    public function listNotes(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/customers/' . ModuleContext::seg($id) . '/notes', 'options' => $options])->data;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> the note */
    public function addNote(string $id, string $body, array $options = []): array
    {
        return $this->ctx->write('POST', '/customers/' . ModuleContext::seg($id) . '/notes', ['body' => $body], $options)['note'] ?? [];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function deleteNote(string $id, string $noteId, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/customers/' . ModuleContext::seg($id) . '/notes/' . ModuleContext::seg($noteId), null, $options);
    }

    /** @param array<string, mixed> $options @return array{members: list<array<string, mixed>>, total: int} */
    public function listMembers(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/customers/' . ModuleContext::seg($id) . '/members', 'options' => $options])->data;
    }

    /** Links a member (with a verified address, not yet at another company). @param array{memberId: string, role?: string} $input @param array<string, mixed> $options @return array{member: array<string, mixed>, changed: bool} */
    public function linkMember(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/customers/' . ModuleContext::seg($id) . '/members', $input, $options);
    }

    /** @param array<string, mixed> $options @return array{unlinked: true, id: string} */
    public function unlinkMember(string $id, string $memberId, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/customers/' . ModuleContext::seg($id) . '/members/' . ModuleContext::seg($memberId), null, $options);
    }

    /** The customer's orders (needs `orders:read` and the module `orders`). @param array<string, mixed> $options @return array{orders: list<array<string, mixed>>, total: int} */
    public function orders(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/customers/' . ModuleContext::seg($id) . '/orders', 'options' => $options])->data;
    }

    /** The customer's invoices (needs `invoicing:read` and the module `invoicing`). @param array<string, mixed> $options @return array{invoices: list<array<string, mixed>>, total: int} */
    public function invoices(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/customers/' . ModuleContext::seg($id) . '/invoices', 'options' => $options])->data;
    }
}
