<?php
// NMS Login Page
$pageTitle = 'Login';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - NMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gradient-to-br from-blue-600 to-blue-900 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-md mx-4" x-data="loginForm()" x-cloak>
        <!-- Logo -->
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-white mb-2">NMS</h1>
            <p class="text-blue-100">Network Management System</p>
        </div>

        <!-- Login Form -->
        <div class="bg-white rounded-lg shadow-xl p-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">Sign In</h2>

            <!-- Error Message -->
            <template x-if="error">
                <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
                    <span x-text="error"></span>
                </div>
            </template>

            <form @submit.prevent="submitLogin" class="space-y-4">
                <!-- Email -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" x-model="email" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                           placeholder="you@example.com">
                </div>

                <!-- Password -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" x-model="password" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                           placeholder="••••••••">
                </div>

                <!-- Submit -->
                <button type="submit" :disabled="loading"
                        class="w-full px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50">
                    <span x-show="!loading">Sign In</span>
                    <span x-show="loading">Signing In...</span>
                </button>
            </form>

            <!-- Footer -->
            <p class="mt-4 text-center text-sm text-gray-600">
                Contact your administrator for access
            </p>
        </div>
    </div>

    <script>
    function loginForm() {
        return {
            email: '',
            password: '',
            loading: false,
            error: '',

            async submitLogin() {
                this.loading = true;
                this.error = '';

                try {
                    const response = await fetch('/api/auth/login', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            email: this.email,
                            password: this.password
                        })
                    });

                    if (!response.ok) {
                        this.error = 'Invalid email or password';
                        return;
                    }

                    const data = await response.json();
                    localStorage.setItem('nms_token', data.token);
                    localStorage.setItem('nms_user', JSON.stringify(data.user));

                    window.location.href = '/dashboard';
                } catch (e) {
                    this.error = 'Login failed: ' + e.message;
                } finally {
                    this.loading = false;
                }
            }
        };
    }
    </script>
</body>
</html>
