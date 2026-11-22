<?php

// Blocks used to pin the live markup of <x-content::page-blocks> (the output must stay byte identical outside a preview).
return [
    ['id' => 'b1', 'type' => 'richText', 'html' => '<p>Hallo <strong>wereld</strong></p>'],
    ['id' => 'b2', 'type' => 'hero', 'heading' => 'Kop', 'text' => 'Tekst', 'ctaLabel' => 'Ga', 'ctaHref' => '/contact'],
    ['type' => 'cta', 'heading' => 'Bel ons', 'label' => 'Bellen', 'href' => 'tel:0101234567'],
    ['id' => 'b4', 'type' => 'form', 'formKey' => 'contact'],
    ['id' => 'b5', 'type' => 'embed', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
    ['id' => 'b6', 'type' => 'columns', 'columns' => [['html' => '<p>Een</p>'], ['html' => '<p>Twee</p>']]],
    ['id' => 'b7', 'type' => 'doesNotExist'],
];
