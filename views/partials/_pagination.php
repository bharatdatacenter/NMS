<?php
/**
 * Pagination controls
 * Usage: include __DIR__ . '/../partials/_pagination.php', ['page' => 1, 'totalPages' => 5, 'baseUrl' => '/devices']
 */
$page = (int)($page ?? 1);
$totalPages = (int)($totalPages ?? 1);
$baseUrl = $baseUrl ?? '#';
$pageParam = $pageParam ?? 'page';
?>

<?php if ($totalPages > 1): ?>
<nav class="flex items-center justify-between mt-6" aria-label="Pagination">
    <!-- Previous -->
    <a href="<?php echo htmlspecialchars($baseUrl . '?' . $pageParam . '=' . max(1, $page - 1)); ?>"
       class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 <?php echo $page === 1 ? 'opacity-50 cursor-not-allowed' : ''; ?>"
       <?php echo $page === 1 ? 'disabled' : ''; ?>>
        Previous
    </a>

    <!-- Page Numbers -->
    <div class="flex space-x-1">
        <?php
        // Show first page, current range, and last page
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i === 1 || $i === $totalPages || ($i >= $page - 1 && $i <= $page + 1)) {
                $isActive = $i === $page;
                ?>
                <a href="<?php echo htmlspecialchars($baseUrl . '?' . $pageParam . '=' . $i); ?>"
                   class="relative inline-flex items-center px-3 py-2 border text-sm font-medium rounded-md <?php echo $isActive ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 text-gray-700 bg-white hover:bg-gray-50'; ?>">
                    <?php echo $i; ?>
                </a>
                <?php
            } elseif (($i === 2 && $page > 3) || ($i === $totalPages - 1 && $page < $totalPages - 2)) {
                echo '<span class="px-2 py-2">...</span>';
            }
        }
        ?>
    </div>

    <!-- Next -->
    <a href="<?php echo htmlspecialchars($baseUrl . '?' . $pageParam . '=' . min($totalPages, $page + 1)); ?>"
       class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 <?php echo $page === $totalPages ? 'opacity-50 cursor-not-allowed' : ''; ?>"
       <?php echo $page === $totalPages ? 'disabled' : ''; ?>>
        Next
    </a>
</nav>
<?php endif; ?>
