// Global NMS application state and utilities
function app() {
    return {
        // Sidebar state
        sidebarOpen: true,
        userMenuOpen: false,
        breadcrumb: [],
        expand: {
            devices: false,
            infra: false,
            topology: false,
            routing: false,
            firewall: false,
            admin: false
        },

        // Authentication
        init() {
            // Initialize Alpine store for auth
            Alpine.store('auth', {
                token: this.getToken(),
                user: this.getUser(),
                refreshTimer: null
            });

            // Set up auto-refresh of JWT token
            this.setupTokenRefresh();
        },

        getToken() {
            return localStorage.getItem('nms_token');
        },

        setToken(token) {
            localStorage.setItem('nms_token', token);
            Alpine.store('auth').token = token;
            this.setupTokenRefresh();
        },

        getUser() {
            const data = localStorage.getItem('nms_user');
            return data ? JSON.parse(data) : null;
        },

        setUser(user) {
            localStorage.setItem('nms_user', JSON.stringify(user));
            Alpine.store('auth').user = user;
        },

        setupTokenRefresh() {
            const store = Alpine.store('auth');
            if (store.refreshTimer) {
                clearInterval(store.refreshTimer);
            }
            // Refresh token every 5 minutes if present
            if (store.token) {
                store.refreshTimer = setInterval(() => {
                    this.refreshToken();
                }, 5 * 60 * 1000);
            }
        },

        async refreshToken() {
            try {
                const response = await fetch('/api/auth/refresh', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + Alpine.store('auth').token }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.setToken(data.token);
                }
            } catch (e) {
                console.error('Token refresh failed:', e);
            }
        },

        async logout() {
            try {
                await fetch('/api/auth/logout', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + Alpine.store('auth').token }
                });
            } catch (e) {
                console.error('Logout failed:', e);
            }
            localStorage.removeItem('nms_token');
            localStorage.removeItem('nms_user');
            window.location.href = '/login';
        },

        // API Helper
        async apiCall(url, options = {}) {
            const headers = options.headers || {};
            const token = Alpine.store('auth').token;
            if (token) {
                headers['Authorization'] = 'Bearer ' + token;
            }
            headers['Content-Type'] = 'application/json';

            const response = await fetch(url, {
                ...options,
                headers
            });

            if (response.status === 401) {
                this.logout();
                return null;
            }

            return response;
        },

        // Utilities
        formatDate(date) {
            if (!date) return '';
            return new Date(date).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },

        statusColor(status) {
            const colors = {
                'online': 'text-green-600',
                'offline': 'text-red-600',
                'drifted': 'text-yellow-600',
                'clean': 'text-green-600',
                'pending': 'text-blue-600',
                'error': 'text-red-600'
            };
            return colors[status] || 'text-gray-600';
        }
    };
}

// Wait for Alpine to be ready, then initialize
document.addEventListener('alpine:initialized', () => {
    const appInstance = Alpine.data('app', app);
    if (appInstance) {
        appInstance().init();
    }
});
