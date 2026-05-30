<?php
// Flash messages from session
$messages = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
?>

<div class="space-y-2" x-data="flashMessages()" x-init="showMessages()">
    <template x-for="msg in messages" :key="msg.id">
        <div :class="{
            'bg-green-100 border border-green-400 text-green-700': msg.type === 'success',
            'bg-red-100 border border-red-400 text-red-700': msg.type === 'error',
            'bg-yellow-100 border border-yellow-400 text-yellow-700': msg.type === 'warning',
            'bg-blue-100 border border-blue-400 text-blue-700': msg.type === 'info',
        }" class="px-4 py-3 rounded relative flex justify-between items-center" role="alert" :id="msg.id">
            <span x-text="msg.text"></span>
            <button @click="removeMessage(msg.id)" class="ml-4 font-bold">×</button>
        </div>
    </template>
</div>

<script>
function flashMessages() {
    return {
        messages: <?php echo json_encode(array_map(function($msg, $idx) {
            return [
                'id' => $idx,
                'type' => $msg['type'] ?? 'info',
                'text' => $msg['text'] ?? ''
            ];
        }, $messages, array_keys($messages))); ?>,

        showMessages() {
            this.messages.forEach((msg, idx) => {
                setTimeout(() => {
                    this.removeMessage(msg.id);
                }, 5000);
            });
        },

        removeMessage(id) {
            this.messages = this.messages.filter(m => m.id !== id);
        }
    };
}
</script>
