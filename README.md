Of course. Here is the complete and updated README.md file.

This new version has been thoroughly rewritten to reflect the refactored code's more robust, state-driven architecture. It explains the new state machine, the decomposition of logic, and the enhanced security and validation measures, providing a professional and accurate guide to the improved bot.

================================================
FILE: README.md
AI-Powered Futures Trading Bot

This project is a sophisticated PHP-based trading bot designed for automated futures trading on Binance. It leverages a Gemini AI model for intelligent decision-making and is built upon a robust, state-driven, asynchronous architecture to ensure high performance, stability, and resilience against race conditions.

Features

Automated Trading: Executes trades on Binance Futures based on AI-driven signals.

AI Integration: Leverages the Gemini AI model for dynamic and adaptive trading strategies.

Robust State Machine Architecture: The bot's core logic is governed by a formal state machine (IDLE, ORDER_PENDING, POSITION_ACTIVE, etc.), which prevents race conditions and ensures predictable, safe behavior in a high-concurrency environment.

Real-time Data: Receives real-time market data (Kline) and user account updates via Binance WebSockets, which drive state transitions.

Secure API Key Management: Stores and decrypts Binance and Gemini API keys securely using application-level encryption.

Hardened Validation: Implements strict, multi-layered validation for both bot configurations at startup and AI-provided trade parameters before execution, acting as a critical safety supervisor.

Automated Risk Management: Implements automatic Stop Loss (SL) and Take Profit (TP) order placement to manage trade risk immediately after a position is opened.

Comprehensive Logging: Detailed logging of all trading orders, AI interactions, and bot state transitions to a MySQL/MariaDB database and standard output.

Environment Flexibility: Supports both Binance production and testnet environments.

Dynamic Strategy Updates: The AI can suggest and update its own trading strategy directives in the database, allowing for continuous learning and adaptation (if configured).

Architecture Overview

The bot's architecture is designed for high performance and reliability, built with PHP and leveraging asynchronous programming. The core AiTradingBotFutures class acts as a stateful orchestrator, managing all logic, API interactions, and AI decision-making through a well-defined lifecycle.

Generated mermaid
graph TD
    A[User] --> B(Bot Configuration DB);
    A --> C(User API Keys DB);
    B --> D{AiTradingBotFutures Instance};
    C --> D;
    D -- Governed by --> SM[State Machine];
    SM -- Triggers --> D;
    D -- Loads --> E[Trade Logic Source DB];
    D -- Logs --> F[Orders Log DB];
    D -- Logs --> G[AI Interactions Log DB];
    D -- Updates --> H[Bot Runtime Status DB];
    D -- Real-time Market Data --> I[Binance Futures WebSocket];
    D -- User Data Stream --> I;
    I -- Events trigger --> SM;
    D -- REST API Calls --> J[Binance Futures REST API];
    D -- AI Prompts (JSON) --> K[Gemini AI Model];
    K -- AI Decisions (JSON) --> D;
    D -- Asynchronous Operations --> L[ReactPHP Event Loop];
    L -- HTTP Requests --> J;
    L -- WebSocket Connections --> I;
    L -- Timers trigger --> SM;
    subgraph Dependencies
        L --> M[React/EventLoop];
        L --> N[React/Socket];
        L -- > O[React/Http];
        I --> P[Ratchet/Pawl];
        D --> Q[Monolog];
        D --> R[phpdotenv];
    end


Core Component: The AiTradingBotFutures PHP class is the central orchestrator. It has been refactored to be a stateful controller, delegating tasks and managing the bot's lifecycle.

State Machine: This is the heart of the refactored bot. A single $botState property dictates what the bot is allowed to do at any moment. This eliminates race conditions by ensuring, for example, that a new position cannot be opened while another one is already pending or active. All major events (WebSocket messages, timer fires, AI responses) are processed based on the current state.

Asynchronous Operations: ReactPHP provides the core event loop, enabling non-blocking I/O for concurrent network operations (HTTP requests and WebSockets) and efficient timer management.

WebSocket Communication: Ratchet/Pawl is used to establish persistent WebSocket connections to Binance. These connections provide the real-time events that drive the state machine.

REST API Interaction: The bot's internal "API component" handles all signed and unsigned REST API calls to Binance for operations like placing orders, fetching balances, and setting leverage.

Database (MySQL/MariaDB): The database is critical for persistence and state management, storing configurations, encrypted API keys, and comprehensive logs of all orders, AI interactions, and runtime statuses.

AI Integration: The bot's internal "AI service component" manages communication with the Gemini AI, including prompt construction, API requests, and response parsing.

Logging & Environment: Monolog and phpdotenv are used for structured logging and secure environment configuration, respectively.

Logical Functioning of the Bot (State-Driven Lifecycle)

The refactored bot operates as a state-driven application. Its actions are strictly controlled by its current state, ensuring a logical and safe operational flow.

Initialization (STATE_INITIALIZING):

On startup, the bot loads environment variables, connects to the database, and fetches its configuration.

Configuration Validation: A strict validation check is performed on the loaded configuration. If any setting is invalid (e.g., leverage out of range), the bot will fail to start.

It securely loads and decrypts the required API keys.

It fetches initial account and market data from Binance REST APIs.

It establishes WebSocket connections and sets up periodic timers.

Based on whether an existing position is found, it transitions to its initial operational state: STATE_IDLE (no position) or STATE_POSITION_UNPROTECTED (existing position needs management).

The IDLE State:

This is the default waiting state. The bot has no open positions and no pending entry orders.

It waits for a periodic timer to fire triggerAIUpdate().

The EVALUATING State:

Entered when an AI update cycle begins. The bot is locked from starting another evaluation until this one completes.

Data Collection (collectDataForAI): A comprehensive snapshot of the market, account, and bot's current state is gathered.

AI Interaction: The data is sent to the Gemini AI, which returns a decision (OPEN_POSITION, CLOSE_POSITION, etc.).

Decision Dispatching (executeAIDecision): The AI's decision is dispatched to a handler based on the bot's state before it entered EVALUATING. If the bot was IDLE, the decision goes to handleDecisionInIdleState().

Executing an OPEN_POSITION Decision:

handleDecisionInIdleState() receives the OPEN_POSITION command.

AI Parameter Validation: The bot performs its own strict validation on the AI's suggested parameters (price, quantity, SL/TP). It checks for valid numbers and logical consistency (e.g., for a LONG, SL must be < Entry Price). If invalid, the action is rejected, and the bot returns to STATE_IDLE.

If valid, attemptOpenPosition() is called, which places a limit order on Binance.

The bot immediately transitions to STATE_ORDER_PENDING.

The ORDER_PENDING State:

The bot is now waiting for its entry order to be filled. It monitors two things:

A WebSocket ORDER_TRADE_UPDATE event with a FILLED status.

A periodic timer that will cancel the order if it remains unfilled for too long (e.g., 60 seconds).

If the order is filled, the onOrderTradeUpdate() handler is triggered.

The POSITION_UNPROTECTED State:

This is a critical, short-lived state entered immediately after an entry order is filled. The bot is in a position but does not yet have its SL and TP orders in place.

Its only goal in this state is to place the protective orders by calling placeSlAndTpOrders().

If placing the SL/TP orders succeeds, the bot transitions to STATE_POSITION_ACTIVE.

If placing them fails, it will attempt an emergency market close of the position to eliminate risk.

The POSITION_ACTIVE State:

This is the standard "in-a-trade" state. The position is open and protected by SL and TP orders.

The bot now monitors for several events:

An ORDER_TRADE_UPDATE message indicating the SL or TP has been hit.

A timer firing triggerAIUpdate(), which could lead to an AI decision to close the position early.

A timer firing checkProfitTarget() if a manual profit target is set.

The CLOSING State:

This state is entered whenever a position needs to be closed (due to SL/TP fill, AI decision, etc.).

The handlePositionClosed() function is the entry point. It immediately cancels any remaining protective orders (e.g., if TP is hit, the SL order is cancelled).

Once all cleanup is complete, the bot transitions back to STATE_IDLE, ready for the next trade.

Functions Present and Their Internal Working (Refactored)

The AiTradingBotFutures class is now organized into logical components, with key methods driving the state machine.

Core Lifecycle & State Management

__construct(...): Initializes the bot, dependencies, logger, and loads all configurations.

run(): The main entry point that starts the event loop and the entire bot lifecycle.

stop(): Gracefully stops the bot, cancels timers, and closes connections.

transitionToState(string $newState, array $context = []): The central state management function. All changes to the bot's operational state must pass through this method, which logs the transition and ensures predictable behavior.

$botState: A private string property that holds the current state of the bot (e.g., STATE_IDLE, STATE_POSITION_ACTIVE). This replaces the old collection of boolean flags.

WebSocket & Timer Event Handlers

handleUserDataStreamEvent(array $eventData): A dispatcher that routes incoming user data events to more specific handlers.

onOrderTradeUpdate(array $orderData): The primary driver of state transitions during a trade. It handles order fills, cancellations, and rejections, moving the bot from ORDER_PENDING to POSITION_UNPROTECTED, and triggering the final cleanup when a position is closed.

onAccountUpdate(array $accountData): Monitors for external account changes, such as a position being closed manually on the Binance website, and triggers a state reconciliation.

onListenKeyExpired(): Manages the renewal of the WebSocket listen key.

setupTimers(): Configures all periodic timers for heartbeats, AI updates, and order timeout checks.

AI Decision & Execution Logic

triggerAIUpdate(bool $isEmergency = false): Initiates the AI decision cycle, transitioning the bot to the EVALUATING state.

executeAIDecision(array $decision): A dispatcher that calls the correct handler for an AI decision based on the bot's current state.

handleDecisionInIdleState(array $decision): Processes an AI decision when the bot is idle. It will only act on an OPEN_POSITION command and will reject others.

handleDecisionInPositionState(array $decision): Processes a decision when a position is active. It will only act on CLOSE_POSITION or HOLD_POSITION.

handleDecisionInUnprotectedState(array $decision): A safety-critical handler that will enforce a CLOSE_POSITION action if the AI doesn't explicitly command it, ensuring an unprotected position is never left open.

validateOpenPositionParams(array $params): A crucial security function that performs strict validation on all parameters provided by the AI before a trade is placed. It checks for logical consistency (e.g., SL/TP placement relative to entry price) in addition to valid data types.

Position & Order Management

attemptOpenPosition(): Called from a state handler to place the initial limit entry order.

placeSlAndTpOrders(): Called immediately after an entry order is filled. Its sole purpose is to place protective SL and TP orders and move the bot to the safe STATE_POSITION_ACTIVE.

attemptClosePositionByAI(bool $isEmergency = false): Initiates a market close of the current position.

handlePositionClosed(): A central cleanup function triggered when any closing event occurs. It cancels any remaining orders and transitions the bot back to the STATE_IDLE.

Recreation Guide

To set up and run a similar bot, follow these steps:

Prerequisites

PHP 8.1+

Composer (PHP dependency manager)

MySQL/MariaDB Server

Binance Futures API Keys (Testnet or Production)

Google Gemini API Key

Setup Steps

Clone the Repository & Install Dependencies:

Generated bash
git clone <repository_url>
cd <repository_name>
composer install
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution. 
Bash
IGNORE_WHEN_COPYING_END

Environment Configuration (.env file):
Create a .env file in the root directory.

Generated dotenv
# Database Configuration
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=server_new
DB_USER=your_db_user
DB_PASSWORD=your_db_password

# Application Encryption Key (Generate a 32-character random string)
APP_ENCRYPTION_KEY=YOUR_VERY_STRONG_32_CHARACTER_RANDOM_KEY_HERE

# Gemini AI Configuration
GEMINI_MODEL_NAME=gemini-pro
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution. 
Dotenv
IGNORE_WHEN_COPYING_END

Security: The APP_ENCRYPTION_KEY is critical. Generate a strong, random key and keep it secret.

Database Setup:
Import the provided SQL schemas into your database.

Generated bash
mysql -u your_db_user -p your_db_name < schema.sql
mysql -u your_db_user -p your_db_name < setupdb.sql
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution. 
Bash
IGNORE_WHEN_COPYING_END

The setupdb.sql file creates a default user and bot configuration. You will need to use the dashboard.php interface to add your own securely encrypted API keys.

Configure Bot Instances & API Keys (Via Dashboard):

Navigate to dashboard.php in your browser.

Create an account.

Go to the "API Keys" section and add your Binance and Gemini API keys. They will be encrypted using your APP_ENCRYPTION_KEY and stored securely.

Go to the "Bots Dashboard" and create a new bot configuration, linking it to the API key set you just created.

Running the Bot

The bot_manager.sh script is the recommended way to manage bot processes.

Make the script executable (one time):

Generated bash
chmod +x bot_manager.sh
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution. 
Bash
IGNORE_WHEN_COPYING_END

Start a bot:
Find the config_id from the dashboard.

Generated bash
./bot_manager.sh start <config_id>
# Example: ./bot_manager.sh start 1
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution. 
Bash
IGNORE_WHEN_COPYING_END

Stop a bot:

Generated bash
./bot_manager.sh stop <config_id>
# Example: ./bot_manager.sh stop 1
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution. 
Bash
IGNORE_WHEN_COPYING_END

Check logs:
The script will tell you the log file path.

Generated bash
tail -f logs/1.log
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution. 
Bash
IGNORE_WHEN_COPYING_END
Troubleshooting

API Key Decryption Failed: Your APP_ENCRYPTION_KEY in .env does not match the key used when you saved your API keys in the dashboard.

Bot Fails to Start: Check the bot's log file (logs/<config_id>.log) for fatal errors. Often caused by an invalid configuration in the database or an inability to connect to Binance or the database.

Bot Ignores AI Decision: This is likely a safety feature. Check the logs for messages about being in an "unexpected state" or "failed validation." The state machine will prevent actions that are not logical for the bot's current situation.