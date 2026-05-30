<?php
/**
 * Status badge component
 * Usage: include __DIR__ . '/../partials/_badge.php', ['status' => 'online', 'label' => 'Active']
 */
$status = $status ?? '';
$label = $label ?? $status;

$statusColors = [
    'online' => 'bg-green-100 text-green-800',
    'offline' => 'bg-red-100 text-red-800',
    'drifted' => 'bg-yellow-100 text-yellow-800',
    'clean' => 'bg-green-100 text-green-800',
    'pending' => 'bg-blue-100 text-blue-800',
    'error' => 'bg-red-100 text-red-800',
    'warning' => 'bg-yellow-100 text-yellow-800',
    'success' => 'bg-green-100 text-green-800',
];

$colorClass = $statusColors[$status] ?? 'bg-gray-100 text-gray-800';
?>

<span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $colorClass; ?>">
    <?php echo htmlspecialchars($label); ?>
</span>
