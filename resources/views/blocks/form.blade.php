<div class="fsc-block fsc-block--form{{ ($look ?? '') === '' ? '' : ' ' . $look }}">
    <x-content::form :form-key="(string) ($block['formKey'] ?? '')" />
</div>
