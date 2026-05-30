<?php
/**
 * Delete confirmation dialog
 * Usage: include __DIR__ . '/../partials/_confirm.php', ['action' => '/api/devices/123', 'message' => 'Delete this device?']
 */
$action = $action ?? '#';
$message = $message ?? 'Are you sure?';
$method = $method ?? 'DELETE';
$confirmId = 'confirmModal_' . uniqid();
?>

<div x-data="{ open: false }" class="inline-block">
    <button @click="open = true" class="px-3 py-1 text-red-600 hover:text-red-900 text-sm">
        Delete
    </button>

    <!-- Modal -->
    <div x-show="open" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div x-show="open" class="fixed inset-0 bg-black bg-opacity-50" @click="open = false"></div>
            <div class="relative inline-block align-bottom bg-white rounded-lg shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <div class="bg-white px-4 py-5 sm:p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Confirm Deletion</h3>
                    <p class="text-gray-600"><?php echo htmlspecialchars($message); ?></p>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse space-y-2 sm:space-y-0 sm:space-x-reverse sm:space-x-3">
                    <form method="POST" action="<?php echo htmlspecialchars($action); ?>" style="display: inline;">
                        <input type="hidden" name="_method" value="<?php echo htmlspecialchars($method); ?>">
                        <button type="submit" class="w-full inline-flex justify-center px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 sm:w-auto">
                            Delete
                        </button>
                    </form>
                    <button @click="open = false" class="w-full inline-flex justify-center px-4 py-2 bg-white text-gray-900 rounded border border-gray-300 hover:bg-gray-50 sm:w-auto">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
