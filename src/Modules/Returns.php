<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\ModuleContext;

/**
 * Returns (`$client->returns`; module `returns`): the buyer requests and follows a return with their order token; with a server token also
 * list, create and handle them. Tokens travel in headers (`X-FSC-Order-Token`, `X-FSC-Return-Token`), never in a URL.
 */
final class Returns
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /**
     * The buyer files a return with the order's access token.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     * @return array{return: array<string, mixed>, token: string}
     */
    public function request(string $orderToken, array $input, array $options = []): array
    {
        return $this->ctx->execute(['method' => 'POST', 'path' => '/returns', 'body' => $input, 'headers' => ['X-FSC-Order-Token' => $orderToken], 'options' => $options])->data;
    }

    /** The buyer follows a return with the return token. @param array<string, mixed> $options @return array<string, mixed> */
    public function get(string $returnToken, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/returns/by-token', 'headers' => ['X-FSC-Return-Token' => $returnToken], 'options' => $options])->data['return'] ?? [];
    }

    /** @param array<string, mixed> $params the filters of the API (`status`, `order`, `page`, `limit`, ...) @param array<string, mixed> $options @return array<string, mixed> */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute(['path' => '/returns', 'query' => $params, 'options' => $options]));
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function getById(string $ref, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/returns/' . ModuleContext::seg($ref), 'options' => $options])->data['return'] ?? [];
    }

    /** Server token: files a return on behalf of a buyer. @param array<string, mixed> $input @param array<string, mixed> $options @return array{return: array<string, mixed>, token: string} */
    public function create(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/returns', $input, $options);
    }

    /** Handle a return: `approve`, `reject`, `receive`, `refund`, ... @param array<string, mixed> $input @param array<string, mixed> $options @return array{return: array<string, mixed>, changed: bool} */
    public function action(string $ref, string $action, array $input = [], array $options = []): array
    {
        return $this->ctx->write('POST', '/returns/' . ModuleContext::seg($ref) . '/' . ModuleContext::seg($action), $input, $options);
    }
}
