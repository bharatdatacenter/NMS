<?php
/**
 * Reusable sortable/filterable data table partial
 * Usage: include __DIR__ . '/../partials/_table.php', ['columns' => [...], 'rows' => [...]]
 */
$columns = $columns ?? [];
$rows = $rows ?? [];
$sortBy = $sortBy ?? '';
$sortOrder = $sortOrder ?? 'asc';
$actions = $actions ?? null;
?>

<div class="overflow-x-auto bg-white rounded-lg border border-gray-200">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <?php foreach ($columns as $col): ?>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <?php if ($col['sortable'] ?? false): ?>
                            <a href="<?php echo '?sort=' . $col['key'] . '&order=' . ($sortOrder === 'asc' ? 'desc' : 'asc'); ?>"
                               class="flex items-center space-x-1 hover:text-gray-700">
                                <span><?php echo htmlspecialchars($col['label']); ?></span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                                </svg>
                            </a>
                        <?php else: ?>
                            <?php echo htmlspecialchars($col['label']); ?>
                        <?php endif; ?>
                    </th>
                <?php endforeach; ?>
                <?php if ($actions): ?>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="<?php echo count($columns) + ($actions ? 1 : 0); ?>" class="px-6 py-4 text-center text-gray-500">
                        No data found
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr class="hover:bg-gray-50">
                        <?php foreach ($columns as $col): ?>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php
                                    $value = $row[$col['key']] ?? '';
                                    if ($col['render'] ?? false) {
                                        echo call_user_func($col['render'], $value, $row);
                                    } else {
                                        echo htmlspecialchars($value);
                                    }
                                ?>
                            </td>
                        <?php endforeach; ?>
                        <?php if ($actions): ?>
                            <td class="px-6 py-4 whitespace-nowrap text-sm flex space-x-2">
                                <?php foreach ($actions as $action): ?>
                                    <a href="<?php echo htmlspecialchars(call_user_func($action['url'] ?? fn() => '#', $row)); ?>"
                                       class="text-blue-600 hover:text-blue-900">
                                        <?php echo htmlspecialchars($action['label']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
