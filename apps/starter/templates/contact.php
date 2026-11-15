<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Lumora\Core\InquiryEngine;

$inquiry = lumora_handle_inquiry();
$channelConfig = InquiryEngine::loadChannelConfig($GLOBALS['lumoraRootDir'] ?? null);
$allChannels = is_array($channelConfig['channels'] ?? null) ? $channelConfig['channels'] : [];
$activeChannel = (string)($inquiry['channel'] ?? ($channelConfig['default_channel'] ?? 'general'));
$metaInfo = is_array($inquiry['channel_meta'] ?? null) ? $inquiry['channel_meta'] : [];
$old = is_array($inquiry['data'] ?? null) ? $inquiry['data'] : [];
$fieldErrors = is_array($inquiry['field_errors'] ?? null) ? $inquiry['field_errors'] : [];
$customFields = is_array($metaInfo['fields'] ?? null) ? $metaInfo['fields'] : [];

$formatFieldLabel = static function (string $key, array $def): string {
    if (!empty($def['label'])) {
        return (string)$def['label'];
    }
    if (!empty($def['receipt_label'])) {
        return ucwords(strtolower((string)$def['receipt_label']));
    }
    return ucwords(str_replace('_', ' ', $key));
};

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="lumora-container py-10 sm:py-12 flex-1 max-w-4xl space-y-8">
    <div class="space-y-3 border-b border-[var(--lumora-border)] pb-6">
        <div class="flex flex-wrap items-center gap-2">
            <span class="lumora-badge lumora-badge-accent font-mono"><?= e((string)($metaInfo['code'] ?? 'CHN-01')) ?></span>
            <span class="lumora-badge font-mono">SLA: <?= e((string)($metaInfo['sla'] ?? '1 Business Day')) ?></span>
        </div>
        <h1 class="text-2xl sm:text-3xl font-bold tracking-tight">
            <?= e((string)($metaInfo['heading'] ?? 'Send Us a Message')) ?>
        </h1>
        <p class="text-sm sm:text-base text-[var(--lumora-fg-muted)]">
            Active channel: <strong class="text-[var(--lumora-fg)]"><?= e((string)($metaInfo['label'] ?? 'General Inquiry')) ?></strong>.
            Submissions are protected by CGNAT-safe v2 CSRF tokens, pre-CSRF rate limiting, and ACID SQLite WAL storage.
        </p>
    </div>

    <?php if (count($allChannels) > 1): ?>
        <nav aria-label="Inquiry Channels" class="flex flex-wrap gap-2">
            <?php foreach ($allChannels as $chanSlug => $chanMeta): ?>
                <?php
                if (!is_array($chanMeta)) continue;
                $isActive = (string)$chanSlug === $activeChannel;
                ?>
                <a
                    href="<?= e(u('/contact/?channel=' . rawurlencode((string)$chanSlug))) ?>"
                    class="lumora-btn <?= $isActive ? 'lumora-btn-primary' : 'lumora-btn-secondary' ?> text-xs sm:text-sm"
                    <?= $isActive ? 'aria-current="page"' : '' ?>
                >
                    <span><?= e((string)($chanMeta['label'] ?? $chanSlug)) ?></span>
                    <?php if (!empty($chanMeta['sla'])): ?>
                        <span class="opacity-80 font-mono text-xs hidden sm:inline">· <?= e((string)$chanMeta['sla']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if (!empty($inquiry['success'])): ?>
        <section class="lumora-card p-6 sm:p-8 space-y-4" role="status" aria-live="polite">
            <div class="lumora-alert lumora-alert-success">
                <strong>Inquiry Recorded Successfully.</strong> Your message has been committed to our encrypted local WAL ledger.
            </div>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 text-sm">
                <div class="rounded-md border border-[var(--lumora-border)] bg-[var(--lumora-bg-subtle)] p-4">
                    <dt class="text-xs font-mono uppercase text-[var(--lumora-fg-muted)]">Reference Receipt</dt>
                    <dd class="mt-1 font-mono font-bold text-base text-[var(--lumora-fg)]"><?= e((string)($inquiry['receipt'] ?? '')) ?></dd>
                </div>
                <div class="rounded-md border border-[var(--lumora-border)] bg-[var(--lumora-bg-subtle)] p-4">
                    <dt class="text-xs font-mono uppercase text-[var(--lumora-fg-muted)]">Timestamp (UTC)</dt>
                    <dd class="mt-1 font-mono text-sm text-[var(--lumora-fg)]"><?= e((string)($inquiry['timestamp'] ?? '')) ?></dd>
                </div>
            </dl>
            <div class="pt-2">
                <a href="<?= e(u('/contact/?channel=' . rawurlencode($activeChannel))) ?>" class="lumora-btn lumora-btn-secondary">
                    Submit Another Message
                </a>
            </div>
        </section>
    <?php else: ?>
        <?php if (!empty($inquiry['errors'])): ?>
            <div class="lumora-alert lumora-alert-danger space-y-2" role="alert">
                <strong>Please review the highlighted issues:</strong>
                <ul class="list-disc pl-5 text-sm space-y-1">
                    <?php foreach ($inquiry['errors'] as $err): ?>
                        <li><?= e((string)$err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= e(u('/contact/?channel=' . rawurlencode($activeChannel))) ?>" class="lumora-card p-6 sm:p-8 space-y-6" data-lumora-contact-form novalidate>
            <input type="hidden" name="_csrf" value="<?= e(lumora_csrf_token()) ?>" />
            <input type="hidden" name="_ts" value="<?= (string)time() ?>" />
            <input type="hidden" name="channel" value="<?= e($activeChannel) ?>" />

            <div class="lumora-honeypot" aria-hidden="true">
                <label for="website_url">Website</label>
                <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="name" class="block text-sm font-medium mb-1.5">Full Name *</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="<?= e((string)($old['name'] ?? '')) ?>"
                        required
                        class="lumora-input"
                        data-error="err-name"
                        aria-invalid="<?= isset($fieldErrors['name']) ? 'true' : 'false' ?>"
                        <?= isset($fieldErrors['name']) ? 'aria-describedby="err-name"' : '' ?>
                    />
                    <p id="err-name" class="lumora-field-error"><?= e((string)($fieldErrors['name'] ?? '')) ?></p>
                </div>

                <div>
                    <label for="organization" class="block text-sm font-medium mb-1.5">Organization</label>
                    <input
                        type="text"
                        id="organization"
                        name="organization"
                        value="<?= e((string)($old['organization'] ?? '')) ?>"
                        class="lumora-input"
                        data-error="err-organization"
                        aria-invalid="<?= isset($fieldErrors['organization']) ? 'true' : 'false' ?>"
                    />
                    <p id="err-organization" class="lumora-field-error"><?= e((string)($fieldErrors['organization'] ?? '')) ?></p>
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium mb-1.5">Work or Official Email *</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= e((string)($old['email'] ?? '')) ?>"
                        required
                        class="lumora-input"
                        data-error="err-email"
                        aria-invalid="<?= isset($fieldErrors['email']) ? 'true' : 'false' ?>"
                        <?= isset($fieldErrors['email']) ? 'aria-describedby="err-email"' : '' ?>
                    />
                    <p id="err-email" class="lumora-field-error"><?= e((string)($fieldErrors['email'] ?? '')) ?></p>
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium mb-1.5">
                        Phone Number <span data-lumora-phone-required hidden>*</span>
                    </label>
                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        value="<?= e((string)($old['phone'] ?? '')) ?>"
                        class="lumora-input"
                        data-error="err-phone"
                        aria-invalid="<?= isset($fieldErrors['phone']) ? 'true' : 'false' ?>"
                    />
                    <p id="err-phone" class="lumora-field-error"><?= e((string)($fieldErrors['phone'] ?? '')) ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="consultation_mode" class="block text-sm font-medium mb-1.5">Consultation Mode</label>
                    <?php $selectedMode = (string)($old['consultation_mode'] ?? 'online'); ?>
                    <select id="consultation_mode" name="consultation_mode" data-lumora-delivery-mode class="lumora-select">
                        <option value="online" <?= $selectedMode === 'online' ? 'selected' : '' ?>>Online Consultation First</option>
                        <option value="onsite" <?= $selectedMode === 'onsite' ? 'selected' : '' ?>>On-Site Assessment at Our Facility</option>
                    </select>
                </div>

                <div data-lumora-onsite-fields <?= $selectedMode === 'onsite' ? '' : 'hidden' ?>>
                    <label for="facility_location" class="block text-sm font-medium mb-1.5">Facility City &amp; Location *</label>
                    <input
                        type="text"
                        id="facility_location"
                        name="facility_location"
                        value="<?= e((string)($old['facility_location'] ?? '')) ?>"
                        class="lumora-input"
                        data-error="err-facility_location"
                        aria-invalid="<?= isset($fieldErrors['facility_location']) ? 'true' : 'false' ?>"
                    />
                    <p id="err-facility_location" class="lumora-field-error"><?= e((string)($fieldErrors['facility_location'] ?? '')) ?></p>
                </div>
            </div>

            <?php if (!empty($customFields)): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 pt-2 border-t border-[var(--lumora-border)]">
                    <?php foreach ($customFields as $fieldKey => $fieldDef): ?>
                        <?php
                        if (!is_array($fieldDef)) continue;
                        $fKey = (string)$fieldKey;
                        $fId = 'field_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $fKey);
                        $fErrId = 'err-' . $fKey;
                        $fReq = !empty($fieldDef['required']);
                        $fLabel = $formatFieldLabel($fKey, $fieldDef) . ($fReq ? ' *' : '');
                        $fOld = (string)($old[$fKey] ?? '');
                        $fType = (string)($fieldDef['type'] ?? 'string');
                        $fHasErr = isset($fieldErrors[$fKey]);
                        ?>
                        <div>
                            <label for="<?= e($fId) ?>" class="block text-sm font-medium mb-1.5"><?= e($fLabel) ?></label>
                            <?php if ($fType === 'enum' && !empty($fieldDef['options']) && is_array($fieldDef['options'])): ?>
                                <select
                                    id="<?= e($fId) ?>"
                                    name="<?= e($fKey) ?>"
                                    class="lumora-select"
                                    <?= $fReq ? 'required' : '' ?>
                                    data-error="<?= e($fErrId) ?>"
                                    aria-invalid="<?= $fHasErr ? 'true' : 'false' ?>"
                                    <?= $fHasErr ? 'aria-describedby="' . e($fErrId) . '"' : '' ?>
                                >
                                    <option value="">Select an option...</option>
                                    <?php foreach ($fieldDef['options'] as $opt): ?>
                                        <?php $optVal = (string)$opt; ?>
                                        <option value="<?= e($optVal) ?>" <?= $fOld === $optVal ? 'selected' : '' ?>>
                                            <?= e($optVal) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input
                                    type="text"
                                    id="<?= e($fId) ?>"
                                    name="<?= e($fKey) ?>"
                                    value="<?= e($fOld) ?>"
                                    class="lumora-input"
                                    <?= $fReq ? 'required' : '' ?>
                                    <?= !empty($fieldDef['min_length']) ? 'minlength="' . (int)$fieldDef['min_length'] . '"' : '' ?>
                                    <?= !empty($fieldDef['max_length']) ? 'maxlength="' . (int)$fieldDef['max_length'] . '"' : '' ?>
                                    data-error="<?= e($fErrId) ?>"
                                    aria-invalid="<?= $fHasErr ? 'true' : 'false' ?>"
                                    <?= $fHasErr ? 'aria-describedby="' . e($fErrId) . '"' : '' ?>
                                />
                            <?php endif; ?>
                            <p id="<?= e($fErrId) ?>" class="lumora-field-error"><?= e((string)($fieldErrors[$fKey] ?? '')) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div>
                <label for="subject" class="block text-sm font-medium mb-1.5">Subject *</label>
                <input
                    type="text"
                    id="subject"
                    name="subject"
                    value="<?= e((string)($old['subject'] ?? '')) ?>"
                    placeholder="<?= e((string)($metaInfo['subject_placeholder'] ?? '')) ?>"
                    required
                    class="lumora-input"
                    data-error="err-subject"
                    aria-invalid="<?= isset($fieldErrors['subject']) ? 'true' : 'false' ?>"
                    <?= isset($fieldErrors['subject']) ? 'aria-describedby="err-subject"' : '' ?>
                />
                <p id="err-subject" class="lumora-field-error"><?= e((string)($fieldErrors['subject'] ?? '')) ?></p>
            </div>

            <div>
                <div class="flex items-center justify-between gap-2 mb-1.5">
                    <label for="message" class="block text-sm font-medium"><?= e((string)($metaInfo['message_label'] ?? 'Message *')) ?></label>
                    <small data-lumora-char-counter class="font-mono text-xs text-[var(--lumora-fg-muted)]">0 / 4000 CHARS · MIN 15</small>
                </div>
                <textarea
                    id="message"
                    name="message"
                    rows="5"
                    minlength="15"
                    maxlength="4000"
                    placeholder="<?= e((string)($metaInfo['message_placeholder'] ?? '')) ?>"
                    required
                    class="lumora-textarea"
                    data-error="err-message"
                    aria-invalid="<?= isset($fieldErrors['message']) ? 'true' : 'false' ?>"
                    <?= isset($fieldErrors['message']) ? 'aria-describedby="err-message"' : '' ?>
                ><?= e((string)($old['message'] ?? '')) ?></textarea>
                <p id="err-message" class="lumora-field-error"><?= e((string)($fieldErrors['message'] ?? '')) ?></p>
            </div>

            <div class="pt-1">
                <label class="inline-flex items-start gap-2.5 text-sm cursor-pointer">
                    <input
                        type="checkbox"
                        id="consent"
                        name="consent"
                        value="1"
                        <?= !empty($old['consent']) ? 'checked' : '' ?>
                        required
                        class="mt-1 h-4 w-4 rounded border border-[var(--lumora-border-strong)]"
                        data-error="err-consent"
                        aria-invalid="<?= isset($fieldErrors['consent']) ? 'true' : 'false' ?>"
                    />
                    <span>
                        I acknowledge the <a href="<?= e(u('/legal/privacy/')) ?>" class="text-[var(--lumora-accent-fg)] underline">Privacy Policy</a>. *
                    </span>
                </label>
                <p id="err-consent" class="lumora-field-error"><?= e((string)($fieldErrors['consent'] ?? '')) ?></p>
            </div>

            <div class="pt-2">
                <button type="submit" class="lumora-btn lumora-btn-primary">
                    <?= e((string)($metaInfo['button_text'] ?? 'Send Inquiry')) ?>
                </button>
            </div>
        </form>
    <?php endif; ?>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
