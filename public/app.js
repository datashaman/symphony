function dashboard() {
    return {
        // State
        status: { status: 'unknown', pid: null },
        agents: [],
        claimed: [],
        retryQueue: [],
        tokens: { input_tokens: 0, output_tokens: 0, estimated_cost_usd: 0 },
        logs: [],
        lastTimestamp: null,
        configContent: '',
        configError: '',
        configSaved: false,

        // UI state
        queueTab: 'claimed',
        autoScroll: true,
        controlling: false,
        savingConfig: false,
        pollInterval: null,
        logPollInterval: null,

        init() {
            this.fetchAll();
            this.loadConfig();

            // Poll state every 5 seconds
            this.pollInterval = setInterval(() => this.fetchAll(), 5000);

            // Poll logs every 2 seconds
            this.logPollInterval = setInterval(() => this.fetchLogs(), 2000);
        },

        async fetchAll() {
            await Promise.allSettled([
                this.fetchStatus(),
                this.fetchAgents(),
                this.fetchQueue(),
                this.fetchTokens(),
            ]);
        },

        async fetchStatus() {
            try {
                const res = await fetch('/api/status');
                if (res.ok) this.status = await res.json();
            } catch (e) {
                this.status = { status: 'unreachable', pid: null };
            }
        },

        async fetchAgents() {
            try {
                const res = await fetch('/api/agents');
                if (res.ok) {
                    const data = await res.json();
                    this.agents = data.agents || [];
                }
            } catch (e) { /* ignore */ }
        },

        async fetchQueue() {
            try {
                const res = await fetch('/api/queue');
                if (res.ok) {
                    const data = await res.json();
                    this.claimed = data.claimed || [];
                    this.retryQueue = data.retry_queue || [];
                }
            } catch (e) { /* ignore */ }
        },

        async fetchTokens() {
            try {
                const res = await fetch('/api/tokens');
                if (res.ok) this.tokens = await res.json();
            } catch (e) { /* ignore */ }
        },

        async fetchLogs() {
            try {
                let url = '/api/logs?lines=200';
                if (this.lastTimestamp) {
                    url += '&since=' + encodeURIComponent(this.lastTimestamp);
                }
                const res = await fetch(url);
                if (res.ok) {
                    const data = await res.json();
                    if (this.lastTimestamp && data.logs.length > 0) {
                        // Append new lines
                        this.logs = this.logs.concat(data.logs);
                        // Keep last 1000 lines
                        if (this.logs.length > 1000) {
                            this.logs = this.logs.slice(-1000);
                        }
                    } else if (!this.lastTimestamp) {
                        this.logs = data.logs || [];
                    }
                    if (data.last_timestamp) {
                        this.lastTimestamp = data.last_timestamp;
                    }
                    if (this.autoScroll) {
                        this.$nextTick(() => {
                            const viewer = this.$refs.logViewer;
                            if (viewer) viewer.scrollTop = viewer.scrollHeight;
                        });
                    }
                }
            } catch (e) { /* ignore */ }
        },

        async loadConfig() {
            try {
                const res = await fetch('/api/config');
                if (res.ok) {
                    const data = await res.json();
                    this.configContent = data.content || '';
                    this.configError = '';
                }
            } catch (e) {
                this.configError = 'Failed to load configuration';
            }
        },

        async saveConfig() {
            this.savingConfig = true;
            this.configError = '';
            this.configSaved = false;
            try {
                const res = await fetch('/api/config', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ content: this.configContent }),
                });
                const data = await res.json();
                if (res.ok) {
                    this.configSaved = true;
                    setTimeout(() => this.configSaved = false, 3000);
                } else {
                    this.configError = data.error || 'Failed to save';
                }
            } catch (e) {
                this.configError = 'Failed to save configuration';
            } finally {
                this.savingConfig = false;
            }
        },

        async controlAction(action) {
            this.controlling = true;
            try {
                await fetch('/api/control', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action }),
                });
                // Refresh status after a short delay
                setTimeout(() => this.fetchStatus(), 1000);
            } catch (e) { /* ignore */ }
            finally {
                this.controlling = false;
            }
        },

        formatNumber(n) {
            if (n == null) return '0';
            n = parseInt(n);
            if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
            if (n >= 1_000) return (n / 1_000).toFixed(1) + 'K';
            return n.toString();
        },

        formatElapsed(seconds) {
            if (seconds == null) return '-';
            seconds = Math.round(seconds);
            if (seconds < 60) return seconds + 's';
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            if (mins < 60) return mins + 'm ' + secs + 's';
            const hours = Math.floor(mins / 60);
            const remainMins = mins % 60;
            return hours + 'h ' + remainMins + 'm';
        },
    };
}
