<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

$inquiry = lumora_handle_inquiry();
$metaInfo = $inquiry['channel_meta'] ?? [];
$old = $inquiry['data'] ?? [];
$fieldErrors = $inquiry['field_errors'] ?? [];

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['config' => $config ?? []]);
?>
<main id="main-content" class="site-shell" style="padding:3rem 0;max-width:760px;">
    <h1><?= e($metaInfo['heading'] ?? 'Send Us a Message') ?></h1>
    <p style="color:var(--color-muted);margin-bottom:2rem;">
        Channel: <strong><?= e($metaInfo['label'] ?? 'General Inquiry') ?></strong> · Target SLA: <strong><?= e($metaInfo['sla'] ?? '1 Business Day') ?></strong>
    </p>

    <?php if (!empty($inquiry['success'])): ?>
        <section class="card-surface" role="status" aria-live="polite">
            <h2 style="margin-top:0;">Inquiry Recorded</h2>
            <p>Your inquiry has been committed to our encrypted local WAL ledger.</p>
            <p>Reference Receipt: <strong style="font-family:var(--font-mono);"><?= e($inquiry['receipt'] ?? '') ?></strong></p>
            <p>Timestamp: <?= e($inquiry['timestamp'] ?? '') ?></p>
        </section>
    <?php else: ?>
        <?php if (!empty($inquiry['errors'])): ?>
            <div class="card-surface" role="alert" style="border-color:#dc2626;color:#b91c1c;">
                <strong>Please review the following:</strong>
                <ul>
                    <?php foreach ($inquiry['errors'] as $err): ?>
                        <li><?= e((string)$err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= e(u('/contact/')) ?>" class="card-surface" data-lumora-contact-form novalidate>
            <input type="hidden" name="_csrf" value="<?= e(lumora_csrf_token()) ?>" />
            <input type="hidden" name="_ts" value="<?= (string)time() ?>" />
            <input type="hidden" name="channel" value="<?= e($inquiry['channel'] ?? 'general') ?>" />

            <div class="lumora-honeypot" aria-hidden="true">
                <label for="website_url">Website</label>
                <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off" />
            </div>

            <div class="form-field">
                <label for="name">Full Name *</label>
                <input type="text" id="name" name="name" value="<?= e((string)($old['name'] ?? '')) ?>" required />
            </div>

            <div class="form-field">
                <label for="organization">Organization</label>
                <input type="text" id="organization" name="organization" value="<?= e((string)($old['organization'] ?? '')) ?>" />
            </div>

            <div class="form-field">
                <label for="email">Work or Official Email *</label>
                <input type="email" id="email" name="email" value="<?= e((string)($old['email'] ?? '')) ?>" required />
            </div>

            <div class="form-field">
                <label for="consultation_mode">Consultation Mode</label>
                <select id="consultation_mode" name="consultation_mode" data-lumora-delivery-mode>
                    <option value="online">Online Consultation First</option>
                    <option value="onsite">On-Site Assessment at Our Facility</option>
                </select>
            </div>

            <div class="form-field" data-lumora-onsite-fields hidden>
                <label for="facility_location">Facility City &amp; Location *</label>
                <input type="text" id="facility_location" name="facility_location" value="<?= e((string)($old['facility_location'] ?? '')) ?>" />
            </div>

            <div class="form-field">
                <label for="phone">Phone Number <span data-lumora-phone-required hidden>*</span></label>
                <input type="tel" id="phone" name="phone" value="<?= e((string)($old['phone'] ?? '')) ?>" />
            </div>

            <div class="form-field">
                <label for="subject">Subject *</label>
                <input type="text" id="subject" name="subject" value="<?= e((string)($old['subject'] ?? '')) ?>" placeholder="<?= e($metaInfo['subject_placeholder'] ?? '') ?>" required />
            </div>

            <div class="form-field">
                <label for="message"><?= e($metaInfo['message_label'] ?? 'Message *') ?></label>
                <textarea id="message" name="message" rows="5" minlength="15" maxlength="4000" required><?= e((string)($old['message'] ?? '')) ?></textarea>
                <small data-lumora-char-counter style="font-family:var(--font-mono);color:var(--color-muted);">0 / 4000 CHARS · MIN 15</small>
            </div>

            <div class="form-field" style="flex-direction:row;align-items:center;gap:0.5rem;">
                <input type="checkbox" id="consent" name="consent" value="1" required />
                <label for="consent">I acknowledge the <a href="<?= e(u('/legal/privacy/')) ?>">Privacy Notice (DPDP Act 2023)</a>. *</label>
            </div>

            <button type="submit" class="btn-primary"><?= e($metaInfo['button_text'] ?? 'Send Inquiry') ?></button>
        </form>
    <?php endif; ?>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
