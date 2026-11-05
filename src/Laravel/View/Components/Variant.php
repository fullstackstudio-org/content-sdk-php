<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\Laravel\PersonalizationController;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-content::variant key="voip">…</x-content::variant>` inside `<x-content::slot>`: the markup of one variant as an inert `<template>`
 * (never rendered, never indexed as page content). Renders nothing while personalisation is off, so the page is exactly as before.
 */
final class Variant extends Component
{
    public readonly bool $enabled;

    public function __construct(public readonly string $key)
    {
        $this->enabled = PersonalizationController::enabled() && preg_match(PersonalizationController::KEY, $key) === 1 && $key !== 'default';
    }

    public function render(): View
    {
        return view('content::components.variant');
    }
}
