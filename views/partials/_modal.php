<?php
/**
 * Alpine.js modal wrapper
 * Usage: include __DIR__ . '/../partials/_modal.php', ['id' => 'confirmModal', 'title' => 'Confirm']
 */
$id = $id ?? 'modal';
$title = $title ?? '';
$content = $content ?? '';
?>

<div x-show="<?php echo htmlspecialchars($id); ?>" x-transition
     class="fixed inset-0 z-50 overflow-y-auto"
     aria-labelledby="modal-title" role="dialog" aria-modal="true">

    <!-- Background -->
    <div x-show="<?php echo htmlspecialchars($id); ?>" x-transition
         class="fixed inset-0 bg-black bg-opacity-50" @click="<?php echo htmlspecialchars($id); ?> = false"></div>

    <!-- Modal Content -->
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="relative inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <!-- Header -->
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4 flex justify-between items-center border-b">
                <h3 class="text-lg leading-6 font-medium text-gray-900"><?php echo htmlspecialchars($title); ?></h3>
                <button @click="<?php echo htmlspecialchars($id); ?> = false" class="text-gray-400 hover:text-gray-600">
                    <span class="sr-only">Close</span>
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Body -->
            <div class="bg-white px-4 py-5 sm:p-6">
                <?php echo $content; ?>
            </div>

            <!-- Footer -->
            <?php if (isset($footer)): ?>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse space-y-2 sm:space-y-0 sm:space-x-reverse sm:space-x-3">
                    <?php echo $footer; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
