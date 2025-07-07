document.addEventListener('DOMContentLoaded', () => {
    // --- GLOBAL ELEMENTS & STATE ---
    const alertContainer = document.getElementById('alert-container');
    let appInterval; // Holds the setInterval ID for the current view

    // --- UTILITY FUNCTIONS ---
    const showAlert = (message, type = 'success', isTemp = false) => {
        if (!alertContainer) return;
        const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
        const wrapper = document.createElement('div');
        wrapper.innerHTML = `<div class="alert ${alertClass} alert-dismissible fade show" role="alert">${message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
        
        if (isTemp) {
            alertContainer.append(wrapper);
            setTimeout(() => { 
                const alert = wrapper.querySelector('.alert');
                if (alert) {
                    alert.classList.remove('show');
                    // Wait for fade-out transition before removing
                    setTimeout(() => wrapper.remove(), 150);
                }
            }, 5000);
        } else {
            alertContainer.innerHTML = ''; // Clear existing persistent alerts
            alertContainer.append(wrapper);
        }
    };
    
    // --- ASYNC ACTION HANDLERS ---
    window.handleBotAction = async (event) => {
        event.preventDefault();
        const form = event.target;
        const action = form.querySelector('[name="action"]').value;
        const actionText = action.replace(/_/g, ' ');

        if (!confirm(`Are you sure you want to ${actionText}?`)) return;

        const button = form.querySelector('button[type="submit"]');
        const originalButtonHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Working...';

        try {
            let apiPath;
            switch(action) {
                case 'start_bot':
                    apiPath = '/api/bots/start';
                    break;
                case 'stop_bot':
                    apiPath = '/api/bots/stop';
                    break;
                case 'delete_config':
                    apiPath = '/api/bots/delete';
                    break;
                default:
                    throw new Error('Unknown bot action.');
            }

            const response = await fetch(apiPath, { method: 'POST', body: new FormData(form) });
            const data = await response.json();
            
            if (data.status === 'success') {
                showAlert(data.message, 'success', true);
                if (action === 'delete_config') {
                    setTimeout(() => window.location.href = '/dashboard', 1500);
                }
            } else {
                showAlert(data.message || 'An unknown error occurred.', 'danger');
            }
        } catch (error) {
            showAlert('Request failed: ' + error.message, 'danger');
        } finally {
            if (action !== 'delete_config') {
                button.disabled = false;
                button.innerHTML = originalButtonHtml;
            }
        }
    };

    // --- OVERVIEW PAGE SPECIFIC LOGIC ---
    const overviewPage = {
        configId: null,
        elements: { /* cache DOM elements */ },
        
        init() {
            const pageContainer = document.getElementById('bot-overview-page');
            if (!pageContainer) return false;
            
            this.configId = pageContainer.dataset.configId;
            this.cacheElements();
            this.addEventListeners();
            this.runUpdateCycle(); // Initial load
            appInterval = setInterval(() => this.runUpdateCycle(), 7000); // Poll every 7 seconds
            return true;
        },
        
        cacheElements() {
            this.elements = {
                breadcrumbBotName: document.getElementById('breadcrumb-bot-name'),
                statusText: document.getElementById('bot-status-text'),
                pid: document.getElementById('bot-pid'),
                heartbeat: document.getElementById('bot-heartbeat'),
                messagesContainer: document.getElementById('bot-messages-container'),
                controlsContainer: document.getElementById('bot-controls-container'),
                totalProfit: document.getElementById('perf-total-profit'),
                tradesExecuted: document.getElementById('perf-trades-executed'),
                winRate: document.getElementById('perf-win-rate'),
                lastTradeAgo: document.getElementById('perf-last-trade-ago'),
                tradesBody: document.getElementById('recent-trades-body'),
                aiLogsContainer: document.getElementById('ai-logs-container'),
                updateConfigForm: document.getElementById('update-config-form'),
                // New elements for strategy editor
                updateStrategyForm: document.getElementById('update-strategy-form'),
                strategyIdInput: document.getElementById('strategy-id-input'),
                strategyJsonEditor: document.getElementById('strategy-json-editor'),
                strategyNameLabel: document.getElementById('strategy-name-label'),
                strategyVersionLabel: document.getElementById('strategy-version-label'),
                strategyUpdaterLabel: document.getElementById('strategy-updater-label'),
                strategyUpdatedLabel: document.getElementById('strategy-updated-label'),
            };
        },
        
        addEventListeners() {
            this.elements.updateConfigForm.addEventListener('submit', (e) => this.handleConfigUpdate(e));
            this.elements.updateStrategyForm.addEventListener('submit', (e) => this.handleStrategyUpdate(e));
        },
        
        async runUpdateCycle() {
            try {
                const response = await fetch(`/api/bots/overview?id=${this.configId}`);
                if (response.status === 401) {
                    showAlert('Your session has expired. Please log in again.', 'danger');
                    if (appInterval) clearInterval(appInterval);
                    setTimeout(() => window.location.reload(), 3000);
                    return;
                }
                if (!response.ok) throw new Error(`Server responded with status: ${response.status}`);
                
                const result = await response.json();
                if (result.status !== 'success') throw new Error(result.message);
                
                this.updateUI(result.data);

            } catch (error) {
                console.error("Overview update failed:", error);
            }
        },
        
        updateUI(data) {
            const placeholders = document.querySelectorAll('.placeholder-glow');
            if (placeholders.length) placeholders.forEach(p => p.classList.remove('placeholder-glow', 'placeholder'));
            
            // Status Card
            const status = (data.statusInfo.status || 'shutdown').toLowerCase();
            this.elements.statusText.className = `status-${status} text-capitalize`;
            this.elements.statusText.textContent = status.replace(/_/g, ' ');
            this.elements.pid.textContent = data.statusInfo.process_id || 'N/A';
            this.elements.heartbeat.textContent = data.statusInfo.last_heartbeat ? new Date(data.statusInfo.last_heartbeat.replace(' ', 'T') + 'Z').toLocaleString() : 'N/A';
            
            if (data.statusInfo.error_message) {
                this.elements.messagesContainer.innerHTML = `<strong>Bot Messages:</strong><pre class="bg-light border border-danger text-danger p-2 rounded small mt-1">${data.statusInfo.error_message}</pre>`;
            } else {
                this.elements.messagesContainer.innerHTML = '';
            }

            // Controls
            let controlsHtml = '';
            if (status === 'running' || status === 'initializing') {
                controlsHtml = `<form class="d-inline" onsubmit="return handleBotAction(event);"><input type="hidden" name="action" value="stop_bot"><input type="hidden" name="config_id" value="${this.configId}"><input type="hidden" name="pid" value="${data.statusInfo.process_id}"><button type="submit" class="btn btn-danger"><i class="bi bi-stop-circle-fill"></i> Stop Bot</button></form>`;
            } else {
                const isDisabled = data.configuration.is_active == 0 ? 'disabled title="Bot is disabled in config"' : '';
                controlsHtml = `<form class="d-inline" onsubmit="return handleBotAction(event);"><input type="hidden" name="action" value="start_bot"><input type="hidden" name="config_id" value="${this.configId}"><button type="submit" class="btn btn-success" ${isDisabled}><i class="bi bi-play-circle-fill"></i> Start Bot</button></form>`;
            }
            this.elements.controlsContainer.innerHTML = controlsHtml;
            
            // Performance Card
            this.elements.totalProfit.textContent = '$' + data.performance.totalProfit.toFixed(2);
            this.elements.totalProfit.className = data.performance.totalProfit >= 0 ? 'text-success' : 'text-danger';
            this.elements.tradesExecuted.textContent = data.performance.tradesExecuted;
            this.elements.winRate.textContent = data.performance.winRate.toFixed(2) + '%';
            this.elements.lastTradeAgo.textContent = data.performance.lastTradeAgo;

            // Recent Trades Table
            let tradesHtml = '';
            if (data.recentTrades.length === 0) {
                tradesHtml = '<tr><td colspan="7" class="text-center text-muted p-4">No trades recorded yet.</td></tr>';
            } else {
                data.recentTrades.forEach(trade => {
                    const netPnl = trade.realized_pnl_usdt === null ? null : parseFloat(trade.realized_pnl_usdt) - parseFloat(trade.commission_usdt);
                    const pnlText = netPnl === null ? 'N/A' : '$' + netPnl.toFixed(4);
                    const pnlClass = netPnl === null ? '' : (netPnl >= 0 ? 'text-success' : 'text-danger');
                    const tradeInfo = trade.reduce_only ? '<span class="badge bg-info">Reduce</span>' : '<span class="badge bg-secondary">Entry</span>';
                    tradesHtml += `
                        <tr>
                            <td>${trade.symbol}</td>
                            <td><span class="fw-bold text-${trade.side == 'BUY' ? 'success' : 'danger'}">${trade.side}</span></td>
                            <td>${parseFloat(trade.quantity_involved).toString()}</td>
                            <td>$${parseFloat(trade.price_point).toFixed(2)}</td>
                            <td class="fw-bold ${pnlClass}">${pnlText}</td>
                            <td>${new Date(trade.bot_event_timestamp_utc.replace(' ', 'T')+'Z').toLocaleString()}</td>
                            <td>${tradeInfo}</td>
                        </tr>`;
                });
            }
            this.elements.tradesBody.innerHTML = tradesHtml;
            
            // AI Logs
            let aiLogsHtml = '';
            if (data.aiLogs.length === 0) {
                aiLogsHtml = '<p class="text-center text-muted p-4">No AI decisions logged yet.</p>';
            } else {
                data.aiLogs.forEach(log => {
                    const decisionParams = JSON.parse(log.ai_decision_params_json || '{}');
                    const feedback = JSON.parse(log.bot_feedback_json || '{}');

                    let feedbackHtml = '';
                    if (feedback.override_reason) {
                        feedbackHtml = `<span class="text-warning">Bot Override:</span> ${feedback.override_reason}`;
                    } else {
                        feedbackHtml = `<span>${decisionParams.rationale || 'No rationale provided.'}</span>`;
                    }

                    let aiDecisionText = '';
                    if (decisionParams.action) {
                        aiDecisionText = decisionParams.action;
                        if (decisionParams.side) aiDecisionText += ` <strong class="text-${decisionParams.side === 'BUY' ? 'success' : 'danger'}">${decisionParams.side}</strong>`;
                        if (decisionParams.entryPrice) aiDecisionText += ` @ ${decisionParams.entryPrice}`;
                        if (decisionParams.quantity) aiDecisionText += `, Qty: ${decisionParams.quantity}`;
                        if (decisionParams.stopLossPrice) aiDecisionText += `, SL: ${decisionParams.stopLossPrice}`;
                        if (decisionParams.takeProfitPrice) aiDecisionText += `, TP: ${decisionParams.takeProfitPrice}`;
                    } else {
                        aiDecisionText = 'N/A';
                    }
                    
                    aiLogsHtml += `
                        <div class="ai-log-entry mx-2">
                            <div>
                                <span class="text-muted">${new Date(log.log_timestamp_utc.replace(' ', 'T')+'Z').toLocaleString()}</span> - 
                                <strong class="text-primary">${log.executed_action_by_bot}</strong>
                            </div>
                            <div class="ps-2" style="font-size: 0.9em;">
                                <strong>Bot Feedback:</strong> ${feedbackHtml}
                            </div>
                            <div class="ps-2" style="font-size: 0.9em;">
                                <small><strong>Original AI Decision:</strong> ${aiDecisionText}</small>
                            </div>
                        </div>`;
                });
            }
            this.elements.aiLogsContainer.innerHTML = aiLogsHtml;

            // Update strategy editor
            if (data.strategy && document.activeElement !== this.elements.strategyJsonEditor) {
                const prettyJson = JSON.stringify(JSON.parse(data.strategy.strategy_directives_json), null, 2);
                this.elements.strategyJsonEditor.value = prettyJson;
                this.elements.strategyIdInput.value = data.strategy.id;
                this.elements.strategyNameLabel.textContent = data.strategy.source_name || 'N/A';
                this.elements.strategyVersionLabel.textContent = data.strategy.version || 'N/A';
                this.elements.strategyUpdaterLabel.textContent = data.strategy.last_updated_by || 'N/A';
                this.elements.strategyUpdatedLabel.textContent = data.strategy.last_updated_at_utc ? new Date(data.strategy.last_updated_at_utc.replace(' ', 'T') + 'Z').toLocaleString() : 'N/A';
            }


            this.elements.breadcrumbBotName.textContent = data.configuration.name;
            if(document.activeElement.name !== 'name') {
                 this.elements.updateConfigForm.querySelector('[name="name"]').value = data.configuration.name;
            }
        },

        async handleConfigUpdate(event) {
            event.preventDefault();
            const form = event.target;
            const button = form.querySelector('button[type="submit"]');
            const originalButtonHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';

            try {
                const response = await fetch('/api/bots/update-config', { method: 'POST', body: new FormData(form) });
                const data = await response.json();
                showAlert(data.message, data.status, true);
            } catch (error) {
                showAlert('Request failed: ' + error.message, 'danger', true);
            } finally {
                button.disabled = false;
                button.innerHTML = originalButtonHtml;
            }
        },

        async handleStrategyUpdate(event) {
            event.preventDefault();
            const form = event.target;
            const button = form.querySelector('button[type="submit"]');
            
            // Client-side JSON validation
            try {
                JSON.parse(this.elements.strategyJsonEditor.value);
            } catch (e) {
                showAlert('Invalid JSON format. Please correct it before saving.', 'danger', true);
                return;
            }

            const originalButtonHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';

            try {
                const response = await fetch('/api/bots/update-strategy', { method: 'POST', body: new FormData(form) });
                const data = await response.json();
                showAlert(data.message, data.status, true);
                if (data.status === 'success') {
                    // Trigger an immediate data refresh to show the new version number etc.
                    this.runUpdateCycle();
                }
            } catch (error) {
                showAlert('Request failed: ' + error.message, 'danger', true);
            } finally {
                button.disabled = false;
                button.innerHTML = originalButtonHtml;
            }
        }
    };

    // --- MAIN DASHBOARD PAGE SPECIFIC LOGIC ---
    const mainDashboardPage = {
        elements: {
            botCardsContainer: document.getElementById('bot-cards-container')
        },
        init() {
            if (!this.elements.botCardsContainer) return false;
            this.updateBotStatuses();
            appInterval = setInterval(() => this.updateBotStatuses(), 3500);
            return true;
        },
        async updateBotStatuses() {
            try {
                const response = await fetch('/api/bots/statuses');
                if (!response.ok) return;
                const data = await response.json();
                if (data.status !== 'success') return;
                
                this.elements.botCardsContainer.innerHTML = ''; // Clear loading state or previous cards
                if (data.bots.length === 0) {
                    this.elements.botCardsContainer.innerHTML = '<div class="col-12 text-center text-muted p-4">No bot configurations found.</div>';
                    return;
                }
                data.bots.forEach(bot => {
                    const status = (bot.status || 'stopped').toLowerCase();
                    const heartbeat = bot.last_heartbeat ? new Date(bot.last_heartbeat.replace(' ', 'T') + 'Z').toLocaleString() : 'N/A';
                    
                    let actionButtons = '';
                    if (status === 'running' || status === 'initializing') {
                        actionButtons = `<form class="d-inline me-1" onsubmit="return handleBotAction(event);"><input type="hidden" name="action" value="stop_bot"><input type="hidden" name="config_id" value="${bot.id}"><input type="hidden" name="pid" value="${bot.process_id}"><button type="submit" class="btn btn-sm btn-warning"><i class="bi bi-stop-circle"></i> Stop</button></form>`;
                    } else {
                        actionButtons = `<form class="d-inline me-1" onsubmit="return handleBotAction(event);"><input type="hidden" name="action" value="start_bot"><input type="hidden" name="config_id" value="${bot.id}"><button type="submit" class="btn btn-sm btn-success" ${!bot.is_active ? 'disabled' : ''}><i class="bi bi-play-circle"></i> Start</button></form>`;
                    }
                    actionButtons += `<a href="/bots/${bot.id}" class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-eye"></i> View</a>`;
                    if (status !== 'running' && status !== 'initializing') {
                        actionButtons += `<form class="d-inline" onsubmit="return handleBotAction(event);"><input type="hidden" name="action" value="delete_config"><input type="hidden" name="config_id" value="${bot.id}"><button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button></form>`;
                    }
                    
                    const cardHtml = `
                        <div class="col-md-6 col-lg-4" data-bot-id="${bot.id}">
                            <div class="card bot-card shadow-sm">
                                <div class="card-body d-flex flex-column">
                                    <h5 class="card-title mb-1">
                                        <a href="/bots/${bot.id}" class="text-decoration-none text-dark">${bot.name}</a>
                                    </h5>
                                    <h6 class="card-subtitle mb-2 text-muted">${bot.symbol}</h6>
                                    <div class="mb-3">
                                        Status: <span class="badge rounded-pill status-${status} text-capitalize">${status}</span>
                                    </div>
                                    <div class="mb-3">
                                        Total P/L: <span class="fw-bold bot-profit-value">Loading...</span>
                                    </div>
                                    <div class="mt-auto">
                                        <div class="btn-group w-100" role="group" aria-label="Bot Actions">
                                            ${actionButtons}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>`;
                    this.elements.botCardsContainer.insertAdjacentHTML('beforeend', cardHtml);

                    // Fetch individual bot performance data
                    this.fetchBotPerformance(bot.id);
                });
            } catch (error) {
                console.error('Main dashboard update failed:', error);
            }
        },
        async fetchBotPerformance(botId) {
            try {
                const response = await fetch(`/api/bots/overview?id=${botId}`);
                if (!response.ok) throw new Error(`Server responded with status: ${response.status}`);
                const result = await response.json();
                if (result.status !== 'success') throw new Error(result.message);

                const totalProfit = result.data.performance.totalProfit;
                const profitText = '$' + totalProfit.toFixed(2);
                const profitClass = totalProfit >= 0 ? 'text-success' : 'text-danger';

                const botCard = this.elements.botCardsContainer.querySelector(`[data-bot-id="${botId}"]`);
                if (botCard) {
                    const profitSpan = botCard.querySelector('.bot-profit-value');
                    if (profitSpan) {
                        profitSpan.textContent = profitText;
                        profitSpan.className = `fw-bold bot-profit-value ${profitClass}`;
                    }
                }
            } catch (error) {
                console.error(`Failed to fetch performance for bot ${botId}:`, error);
                const botCard = this.elements.botCardsContainer.querySelector(`[data-bot-id="${botId}"]`);
                if (botCard) {
                    const profitSpan = botCard.querySelector('.bot-profit-value');
                    if (profitSpan) {
                        profitSpan.textContent = 'N/A';
                        profitSpan.classList.add('text-muted');
                    }
                }
            }
        }
    };
    
    // --- APP INITIALIZATION ---
    if (!overviewPage.init()) {
        mainDashboardPage.init();
    }
});
