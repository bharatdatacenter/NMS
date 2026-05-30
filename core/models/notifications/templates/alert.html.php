<?php
/**
 * HTML alert template.
 *
 * @var \NMS\Core\Models\Notifications\NotificationMessage $message
 * Returns an HTML string. Used by EmailChannel for the text/html part.
 */

declare(strict_types=1);

$severityColors = [
    0 => '#6b7280',
    1 => '#3b82f6',
    2 => '#f59e0b',
    3 => '#f97316',
    4 => '#ef4444',
    5 => '#991b1b',
];
$color = $severityColors[$message->severity] ?? '#6b7280';

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$refRows = '';
foreach ($message->sourceRef as $key => $value) {
    if (is_scalar($value)) {
        $refRows .= '<tr><td style="padding:2px 12px 2px 0;color:#6b7280;">'
            . $e((string)$key) . '</td><td style="padding:2px 0;color:#111827;font-family:monospace;">'
            . $e((string)$value) . '</td></tr>';
    }
}

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
        <tr><td style="background:<?= $color ?>;height:6px;"></td></tr>
        <tr><td style="padding:24px 28px 8px;">
          <span style="display:inline-block;background:<?= $color ?>;color:#fff;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;padding:3px 10px;border-radius:4px;">
            <?= $e($message->severityLabel()) ?>
          </span>
          <h1 style="margin:14px 0 0;font-size:18px;color:#111827;"><?= $e($message->title) ?></h1>
        </td></tr>
        <tr><td style="padding:8px 28px 0;color:#374151;font-size:14px;line-height:1.6;white-space:pre-line;"><?= $e($message->body) ?></td></tr>
        <?php if ($refRows !== ''): ?>
        <tr><td style="padding:16px 28px 0;">
          <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:13px;"><?= $refRows ?></table>
        </td></tr>
        <?php endif; ?>
        <tr><td style="padding:20px 28px 24px;color:#9ca3af;font-size:12px;border-top:1px solid #f3f4f6;margin-top:16px;">
          Event <code style="color:#6b7280;"><?= $e($message->eventType) ?></code> &middot; <?= $e(gmdate('c')) ?><br>
          NMS Network Management System
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
<?php
return (string)ob_get_clean();
