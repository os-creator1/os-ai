@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Conversations'))


@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection


@section('page-style')
    <!-- Page css files -->
    {{-- app-chat.css / app-chat-list.css still supply this page's off-canvas
         sidebar geometry (mobile slide-in transforms, fixed header/composer
         heights, PerfectScrollbar sizing) that public/js/scripts/pages/chat.js
         reads directly. Removing them would mean re-deriving that geometry
         from scratch with real mobile-regression risk, so this page keeps
         loading them for LAYOUT only — every visual choice they used to make
         (color, gradient, shadow, avatar, canvas, bubble, empty state) is
         overridden below with Business OS tokens, so none of their old skin
         survives on screen. --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/app-chat.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/app-chat-list.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">

    <style>
        /* For screens smaller than 576px */
        @media (max-width: 576px) {
            /* Set the max-width of the image or video to 100% to make it responsive */
            img, video {
                max-width: 100%;
                height: auto;
            }
        }

        /* For screens between 576px and 768px */
        @media (min-width: 576px) and (max-width: 768px) {
            img, video {
                max-width: 100%;
                height: auto;
            }
        }

        /* For screens between 768px and 992px */
        @media (min-width: 768px) and (max-width: 992px) {
            img, video {
                max-width: 100%;
                height: auto;
            }
        }

        /* For screens between 992px and 1200px */
        @media (min-width: 992px) and (max-width: 1200px) {
            img, video {
                max-width: 100%;
                height: auto;
            }
        }

        /* For screens larger than 1200px */
        @media (min-width: 1200px) {
            img, video {
                max-width: 100%;
                height: auto;
            }
        }

        /* Style the textarea to look like an input field */
        textarea.message {
            resize: none; /* Disable resizing */
            overflow: hidden; /* Hide the scrollbar */
            white-space: nowrap; /* Prevent wrapping to the next line */
            height: 38px; /* Set a fixed height (same as most input fields) */
            line-height: 1.5; /* Adjust line height for vertical alignment */
            padding: 8px 12px; /* Match padding of input fields */
            border: 1px solid var(--color-input-border); /* Match border of input fields */
            border-radius: 4px; /* Match border radius of input fields */
            font-family: inherit; /* Use the same font as input fields */
            font-size: 14px; /* Match font size of input fields */
        }

        /* Optional: Add focus styling to match input fields */
        textarea.message:focus {
            border-color: var(--color-focus-border); /* Match focus border color of input fields */
            outline: 0; /* Remove default outline */
            box-shadow: 0 0 0 0.2rem var(--focus-ring-color); /* Match focus shadow of input fields */
        }

        /*
         * Conversations — three panes: the conversation list (content sidebar),
         * the person's activity timeline with the composer under it, and the
         * contact panel. Tokens only; the chat theme keeps drawing bubbles.
         */
        .chat-app-window .active-chat {
            display: flex;
            flex-direction: column;
            height: 100%;
            position: relative;
        }

        .conversation-body {
            display: flex;
            flex: 1 1 auto;
            min-height: 0;
        }

        .conversation-main {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-width: 0;
        }

        .chat-app-window .conversation-main .user-chats {
            flex: 1 1 auto;
            height: auto;
            min-height: 0;
        }

        .conversation-main .chat-app-form {
            flex: 0 0 auto;
        }

        .conversation-header-text {
            min-width: 0;
        }

        .conversation-channel-chip {
            border: 1px solid var(--color-border-subtle);
            border-radius: var(--radius-full);
            color: var(--color-text-secondary);
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            margin-right: var(--space-2);
            padding: var(--space-1) var(--space-2);
        }

        .timeline-divider {
            align-items: center;
            clear: both;
            color: var(--color-text-muted);
            display: flex;
            font-size: 0.75rem;
            gap: var(--space-3);
            margin: var(--space-4) 0 var(--space-3);
        }

        .timeline-divider::before,
        .timeline-divider::after {
            border-top: 1px solid var(--color-border-subtle);
            content: "";
            flex: 1 1 auto;
        }

        .timeline-message {
            clear: both;
        }

        .timeline-message-body {
            white-space: pre-wrap;
            word-break: break-word;
        }

        .chat-app-window .chats .timeline-message .timeline-message-meta {
            font-size: 0.75rem;
            margin-bottom: 0;
            opacity: 0.85;
        }

        .timeline-media img,
        .timeline-media video {
            border-radius: var(--radius-md);
            max-height: 240px;
            max-width: 240px;
        }

        .timeline-activity {
            align-items: center;
            background-color: var(--color-surface);
            border: 1px solid var(--color-border-subtle);
            border-radius: var(--radius-full);
            clear: both;
            color: var(--color-text-secondary);
            display: flex;
            flex-wrap: wrap;
            font-size: 0.8125rem;
            gap: var(--space-2);
            justify-content: center;
            margin: var(--space-2) auto var(--space-3);
            max-width: 85%;
            padding: var(--space-1) var(--space-3);
            width: fit-content;
        }

        .timeline-activity-warning {
            background-color: var(--color-status-warning-soft-bg);
            border-color: var(--color-status-warning-border);
            color: var(--color-status-warning-text);
        }

        .timeline-activity-detail,
        .timeline-activity-time {
            color: var(--color-text-muted);
        }

        .timeline-empty {
            margin-top: var(--space-8);
            text-align: center;
        }

        .conversation-context {
            background-color: var(--color-surface);
            border-left: 1px solid var(--color-border-subtle);
            flex: 0 0 280px;
            min-height: 0;
            overflow-y: auto;
            padding: var(--space-5) var(--space-4);
            width: 280px;
        }

        .conversation-context-person {
            border-bottom: 1px solid var(--color-border-subtle);
            margin-bottom: var(--space-4);
            padding-bottom: var(--space-4);
            text-align: center;
        }

        .conversation-context-avatar {
            font-size: 1.1rem;
            height: 56px;
            width: 56px;
        }

        .conversation-context-facts {
            display: grid;
            font-size: 0.875rem;
            gap: var(--space-2) var(--space-3);
            grid-template-columns: auto 1fr;
            margin-bottom: var(--space-4);
        }

        .conversation-context-facts dt {
            color: var(--color-text-muted);
            font-weight: 400;
        }

        .conversation-context-facts dd {
            color: var(--color-text-primary);
            margin: 0;
            word-break: break-word;
        }

        .conversation-context-heading {
            color: var(--color-text-muted);
            font-size: 0.75rem;
            letter-spacing: 0.04em;
            margin-bottom: var(--space-2);
            text-transform: uppercase;
        }

        @media (max-width: 1199.98px) {
            .conversation-context {
                bottom: 0;
                box-shadow: var(--shadow-lg);
                display: none;
                position: absolute;
                right: 0;
                top: 65px;
                width: min(320px, 100%);
                z-index: 6;
            }

            .conversation-context.show {
                display: block;
            }
        }

        /*
         * app-chat.css paints the "nothing selected" placeholder and the
         * message timeline with a decorative food/drink SVG wallpaper.
         * Replace it with the canvas token reserved for this screen.
         */
        .chat-application .chat-app-window .start-chat-area,
        .chat-application .chat-app-window .user-chats {
            background-color: var(--color-chat-canvas);
            background-image: none;
        }

        /*
         * Modern Business OS pass. The two rules above already stopped the
         * Vuexy wallpaper; everything below stops the rest of its skin —
         * gradients, drop shadows, hardcoded whites — while leaving every
         * geometry rule those two stylesheets still supply (position,
         * transform, flex, the fixed header/composer heights chat.js reads)
         * completely alone.
         */

        /*
         * Open workspace: the inbox is the page, not a card inside it. The
         * theme boxes .content-area-wrapper (1px border, rounded corners) and
         * pads .app-content on every side; drop both so the three panes run
         * edge to edge under the navbar, separated only by hairlines.
         */
        html body .app-content.chat-application {
            padding: var(--conversations-top, 0px) 0 0 !important;
        }

        /* The theme's min-height (100% minus the footer) is taller than the
           measured workspace by a sub-pixel or two and would push the footer
           past the viewport, i.e. a stray page scrollbar. */
        html body .app-content.chat-application {
            min-height: 0 !important;
            /* The theme animates `all` on .content for the menu collapse; only
               the margin needs it, and an animating padding would be measured
               mid-transition by the chrome script below. */
            transition-property: margin-left, margin-right;
        }

        html body .app-content.chat-application .content-area-wrapper {
            border: 0;
            border-radius: 0;
            box-shadow: none;
            height: calc(100vh - var(--conversations-chrome, 7.9rem)) !important;
            height: calc(var(--vh, 1vh) * 100 - var(--conversations-chrome, 7.9rem)) !important;
        }

        .chat-application .content-area-wrapper .content-wrapper,
        .chat-application .content-area-wrapper .content-body {
            padding: 0;
        }

        .chat-application .sidebar-content,
        .conversation-context {
            background-color: var(--color-surface);
            border-radius: 0;
        }

        .chat-application .sidebar-content {
            display: flex;
            flex-direction: column;
            height: 100%;
            border-right: 1px solid var(--color-border-subtle);
        }

        .chat-application .sidebar-content > * {
            flex: 0 0 auto;
        }

        .chat-application .sidebar-content .chat-user-list-wrapper {
            flex: 1 1 0;
            height: auto;
            min-height: 0;
        }

        @media (min-width: 576px) {
            .chat-application .sidebar-content {
                width: 320px;
            }
        }

        .chat-application .sidebar-content .chat-fixed-search,
        .chat-application .sidebar-content .chat-user-list-wrapper {
            width: 100%;
        }

        /* Sidebar filter row: one compact segmented control, not four
           loose, full-height buttons. */
        .sidebar-content .tab-group.btn-group {
            background-color: var(--color-surface-secondary);
            border-radius: var(--radius-md);
            display: flex;
            gap: 0;
            padding: var(--space-1);
            width: 100%;
        }

        .sidebar-content .tab-group.btn-group .tab-button {
            border: 0 !important;
            border-radius: var(--radius-sm) !important;
            box-shadow: none !important;
            flex: 1 1 auto;
            font-size: var(--type-caption-size);
            padding: var(--space-1) var(--space-2) !important;
        }

        .sidebar-content .tab-group.btn-group .tab-button.btn-outline-primary {
            background-color: transparent !important;
            color: var(--color-text-secondary) !important;
        }

        .sidebar-content .tab-group.btn-group .tab-button.btn-outline-primary:hover {
            background-color: var(--color-surface) !important;
            color: var(--color-text-primary) !important;
        }

        .chat-application .sidebar-content .chat-fixed-search {
            align-items: center;
            height: auto;
            padding: var(--space-3) var(--space-4);
        }

        .chat-application .sidebar-content .chat-list-title {
            color: var(--color-text-muted);
            font-size: var(--type-caption-size);
            letter-spacing: 0.04em;
            margin: var(--space-4) var(--space-4) var(--space-2);
            text-transform: uppercase;
        }

        /* Conversation rows: quiet hover, a tokenized selected state
           instead of a purple gradient, and room for an initials avatar. */
        .chat-application .sidebar-content .chat-user-list-wrapper li {
            align-items: center;
            border-radius: var(--radius-md);
            margin: 0 var(--space-2);
            padding: var(--space-3);
            transition: background-color 120ms ease;
            width: calc(100% - (var(--space-2) * 2));
        }

        .chat-application .sidebar-content .chat-user-list-wrapper li:hover {
            background: var(--color-row-hover) !important;
        }

        .chat-application .sidebar-content .chat-user-list-wrapper li.active,
        .chat-application .sidebar-content .chat-user-list-wrapper li.active h1,
        .chat-application .sidebar-content .chat-user-list-wrapper li.active h2,
        .chat-application .sidebar-content .chat-user-list-wrapper li.active h3,
        .chat-application .sidebar-content .chat-user-list-wrapper li.active h4,
        .chat-application .sidebar-content .chat-user-list-wrapper li.active h5,
        .chat-application .sidebar-content .chat-user-list-wrapper li.active h6,
        .chat-application .sidebar-content .chat-user-list-wrapper li.active .card-text,
        .chat-application .sidebar-content .chat-user-list-wrapper li.active .chat-time {
            background: var(--color-primary-soft-bg) !important;
            color: var(--color-text-primary) !important;
        }

        .chat-application .sidebar-content .chat-user-list-wrapper li .chat-info {
            margin-top: 0;
            min-width: 0;
            padding: 0 var(--space-3);
            width: auto !important;
        }

        .chat-application .sidebar-content .chat-user-list-wrapper li.pinned-row {
            border-left: 3px solid var(--color-primary);
            padding-left: calc(var(--space-3) - 3px);
        }

        /* flex-shrink: 0 on both non-text columns is the other half of the
           text-truncate fix above — the meta column (time + unread badge)
           and the avatar must never be the ones that give up their width;
           the name/preview text is what's meant to truncate. */
        .chat-application .sidebar-content .chat-user-list-wrapper .chat-meta {
            align-items: flex-end;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            gap: var(--space-1);
        }

        .chat-application .sidebar-content .chat-user-list-wrapper .chat-time {
            float: none !important;
            margin-bottom: 0 !important;
            white-space: nowrap;
        }

        .chat-application .sidebar-content .chat-user-list-wrapper .notification_count {
            float: none !important;
            font-size: 0.6875rem;
        }

        .conversation-row-avatar {
            align-items: center;
            display: inline-flex;
            flex-shrink: 0;
            font-size: var(--type-label-size);
            font-weight: var(--type-label-weight);
            height: 40px !important;
            justify-content: center;
            width: 40px !important;
        }

        /* The deliberate empty-selection state, replacing the old floating
           icon-in-a-shadow-chip look. */
        .start-chat-area {
            margin: 0 auto;
            max-width: 320px;
            padding: var(--space-6);
            text-align: center;
        }

        .start-chat-area .start-chat-icon {
            align-items: center;
            background: var(--color-primary-soft-bg) !important;
            box-shadow: none !important;
            color: var(--color-primary);
            display: inline-flex;
            height: 72px;
            justify-content: center;
            padding: 0 !important;
            width: 72px;
        }

        .start-chat-area .start-chat-icon svg {
            height: 28px !important;
            width: 28px !important;
        }

        .start-chat-area .start-chat-text {
            background: none !important;
            box-shadow: none !important;
            color: var(--color-text-primary);
            font-size: var(--type-section-heading-size);
            font-weight: var(--type-section-heading-weight);
            padding: 0 !important;
        }

        .start-chat-area .start-chat-description {
            color: var(--color-text-muted);
            font-size: var(--type-body-size);
            margin: var(--space-2) 0 var(--space-4);
        }

        .start-chat-area .btn {
            margin-top: var(--space-2);
        }

        /* Chat header: a quiet surface with a hairline border, not a
           Vuexy-white bar with its own drop shadow. */
        .chat-application .chat-app-window .active-chat .chat-header {
            background-color: var(--color-surface);
            border-bottom: 1px solid var(--color-border-subtle);
            padding: 0 var(--space-4);
        }

        .chat-application .chat-app-window .active-chat .chat-header .cursor-pointer {
            border-radius: var(--radius-sm);
            padding: var(--space-1);
            transition: background-color 120ms ease;
        }

        .chat-application .chat-app-window .active-chat .chat-header .cursor-pointer:hover {
            background-color: var(--color-row-hover);
        }

        /* Composer: wraps instead of overflowing on narrow widths, and the
           template picker gets a fixed lane instead of dominating the row. */
        .chat-application .chat-app-window .chat-app-form {
            flex-wrap: wrap;
            gap: var(--space-2);
            height: auto;
            min-height: 65px;
            padding: var(--space-3) var(--space-4);
        }

        .chat-application .chat-app-window .chat-app-form .form-send-message {
            flex: 1 1 220px;
            margin: 0 !important;
            min-width: 0;
        }

        .composer-template-select {
            flex: 0 0 auto;
            width: 140px;
        }

        @media (max-width: 575.98px) {
            .composer-template-select {
                width: 100%;
            }
        }

        /* Message bubbles: the design system's own chat tokens, not a
           purple gradient with a drop shadow. */
        .chat-application .chat-app-window .chats .chat-body .chat-content {
            background: var(--color-chat-bubble-out);
            box-shadow: none;
            color: var(--color-button-text);
            margin: 0 var(--space-4) var(--space-3) 0;
            max-width: min(560px, 78%);
        }

        .chat-application .chat-app-window .chats .chat-left .chat-body .chat-content {
            background: var(--color-chat-bubble-in);
            color: var(--color-text-primary);
            margin: 0 0 var(--space-3) var(--space-4);
        }

        /* ── Unified inbox: channel filter, channel badges, channel-aware composer ── */
        .sidebar-content .channel-group.btn-group {
            background-color: var(--color-surface-secondary);
            border-radius: var(--radius-md);
            display: flex;
            gap: 0;
            padding: var(--space-1);
            width: 100%;
        }

        .sidebar-content .channel-group .channel-button {
            border: 0 !important;
            border-radius: var(--radius-sm) !important;
            box-shadow: none !important;
            flex: 1 1 0;
            font-size: var(--type-caption-size);
            justify-content: center;
            padding: var(--space-1) var(--space-2) !important;
        }

        .sidebar-content .channel-group .channel-button.btn-outline-primary {
            background-color: transparent !important;
            color: var(--color-text-secondary) !important;
        }

        .conversation-channel-badge {
            color: var(--color-text-muted);
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .conversation-channel-badge-email {
            color: var(--color-primary);
        }

        .timeline-channel {
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .timeline-email-subject {
            font-weight: 600;
            margin-bottom: var(--space-1);
        }

        .timeline-message[data-channel="email"] .timeline-message-body {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .conversation-channel-switch {
            display: inline-flex;
            gap: 2px;
            margin-right: var(--space-2);
        }

        .conversation-channel-option {
            background: transparent;
            border: 1px solid var(--color-border-subtle);
            border-radius: var(--radius-full);
            color: var(--color-text-secondary);
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            padding: var(--space-1) var(--space-2);
        }

        .conversation-channel-option.is-active {
            background: var(--color-primary-soft-bg);
            border-color: var(--color-primary-border);
            color: var(--color-text-primary);
        }

        .conversation-channel-switch[data-single="1"] .conversation-channel-option {
            cursor: default;
        }

        .composer-email-fields {
            display: flex;
            flex: 1 1 100%;
            gap: var(--space-2);
            order: -1;
        }

        .composer-email-fields[hidden],
        .conversation-channel-option[hidden],
        .composer-guidance[hidden] {
            display: none;
        }

        .composer-email-fields .form-control {
            flex: 1 1 auto;
        }

        .composer-guidance {
            color: var(--color-text-muted);
            flex: 1 1 100%;
        }

        .chat-app-form.is-email-channel .attachment-icon,
        .chat-app-form.is-email-channel .composer-template-select {
            display: none;
        }

        .active-chat.is-email-thread .add-to-blacklist,
        .active-chat.is-email-thread .remove-btn,
        .active-chat.is-email-thread .add-to-pin {
            display: none !important;
        }

        /*
         * Dark mode: ONE continuous workspace surface.
         *
         * Why there were three: the list pane (.sidebar-content) and the contact panel used --color-surface (the dark surface token); the
         * conversation panes were meant to use --color-chat-canvas (the dark canvas token) via the rule above, but the theme's
         * `.dark-layout .chat-application .content-right .chat-app-window .start-chat-area, .user-chats { background-color: <a third, slightly different dark grey> }`
         * is MORE specific, so the panel actually rendered that third grey; and the wrapper behind them kept the page canvas token.
         * Three parents, three hex values. Every pane is now painted with the same canvas token (selectors at least as specific as
         * the theme's own), and the panes are told apart by hairline borders only. The nav sidebar and header are not touched.
         * Everything here is under html.dark-layout, so light mode is unchanged.
         */
        html.dark-layout .chat-application,
        html.dark-layout .chat-application .content-area-wrapper,
        html.dark-layout .chat-application .content-wrapper,
        html.dark-layout .chat-application .content-body,
        html.dark-layout .chat-application .sidebar,
        html.dark-layout .chat-application .sidebar .sidebar-content,
        html.dark-layout .chat-application .sidebar .sidebar-content .chat-fixed-search,
        html.dark-layout .chat-application .sidebar .sidebar-content .chat-user-list-wrapper,
        html.dark-layout .chat-application .conversation-context,
        html.dark-layout .chat-application .content-right,
        html.dark-layout .chat-application .content-right .chat-app-window,
        html.dark-layout .chat-application .content-right .chat-app-window .start-chat-area,
        html.dark-layout .chat-application .content-right .chat-app-window .user-chats,
        html.dark-layout .chat-application .content-right .chat-app-window .active-chat,
        html.dark-layout .chat-application .content-right .chat-app-window .active-chat .chat-header,
        html.dark-layout .chat-application .content-right .chat-app-window .active-chat .chat-app-form,
        html.dark-layout .chat-application .content-right .chat-app-window .active-chat .conversation-main {
            background-color: var(--color-chat-canvas);
        }

        /* Panes are separated by hairlines, not by shade. */
        html.dark-layout .chat-application .sidebar .sidebar-content,
        html.dark-layout .chat-application .conversation-context,
        html.dark-layout .chat-application .content-right .chat-app-window .active-chat .chat-header,
        html.dark-layout .chat-application .content-right .chat-app-window .active-chat .chat-app-form {
            border-color: var(--color-border);
        }

        /* Controls keep their own raised surface so they stay readable on the canvas. */
        html.dark-layout .chat-application .chat-app-form .form-control,
        html.dark-layout .chat-application .chat-app-form .form-select,
        html.dark-layout .chat-application .chat-app-form .input-group-text {
            background-color: var(--color-input-bg);
            border-color: var(--color-input-border);
            color: var(--color-text-primary);
        }

        html.dark-layout .chat-application .content-right .chat-app-window .active-chat .chats .chat-left .chat-content {
            background-color: var(--color-chat-bubble-in);
        }

        /* ── Email: reads like an email client (reading pane, reply box). List rows share .conv-row, below. ── */
        /* Reply box: subject on top, a roomy writing area, Send at the end. */
        .chat-app-form.is-email-channel {
            align-items: flex-start;
        }

        .chat-app-form.is-email-channel .form-send-message {
            flex: 1 1 100%;
            order: 1;
        }

        .chat-app-form.is-email-channel .form-send-message textarea {
            min-height: 110px;
            resize: vertical;
        }

        .chat-app-form.is-email-channel .conversation-channel-switch {
            order: 0;
        }

        .chat-app-form.is-email-channel .send {
            margin-left: auto;
            order: 2;
        }

        .chat-app-form.is-email-channel .composer-email-fields {
            border-bottom: 1px solid var(--color-border-subtle);
            padding-bottom: var(--space-2);
        }

        /* Calmer context panel for an email thread. */
        .conversation-context [data-context="email"] .conversation-context-name {
            font-size: 0.9375rem;
        }

        .conversation-context [data-context="email"] .conversation-context-details dt {
            color: var(--color-text-muted);
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            margin-top: var(--space-3);
            text-transform: uppercase;
        }

        .conversation-context [data-context="email"] .conversation-context-details dd {
            color: var(--color-text-primary);
            font-size: 0.8125rem;
            margin: 0;
            overflow-wrap: anywhere;
        }


        /* ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════
         * Conversations redesign — compact list, calmer filters, message timeline, email cards, composer, contact panel.
         * Theme tokens only: colour, radius, spacing and shadow come from the active preset (light and dark); text inherits
         * the active font (nothing here names a typeface). Every rule below is presentation — no behaviour hook is renamed.
         * ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════ */

        /* ── List pane ── */
        @media (min-width: 576px) {
            .chat-application .sidebar-content {
                width: 340px;
            }
        }

        .chat-application .sidebar-content .channel-group.btn-group {
            background-color: var(--color-surface-secondary);
            border-radius: var(--radius-md);
            margin: var(--space-4) var(--space-4) 0;
            padding: var(--space-1);
            width: auto;
        }

        .chat-application .sidebar-content [data-role="conversation-channels"] {
            padding-top: 0 !important;
        }

        .sidebar-content .channel-group .channel-button {
            font-size: var(--type-label-size);
            font-weight: 600;
            min-height: 34px;
        }

        .sidebar-content .channel-group .channel-button.btn-primary {
            background-color: var(--color-surface) !important;
            box-shadow: var(--shadow-sm) !important;
            color: var(--color-text-primary) !important;
        }

        .chat-application .sidebar-content .chat-fixed-search {
            padding: var(--space-3) var(--space-4);
        }

        .chat-application .sidebar-content .chat-fixed-search .ms-1 {
            margin-left: 0 !important;
        }

        /* Filter tabs: quiet underline tabs, not a second segmented control. */
        .chat-application .sidebar-content .tab-group.btn-group {
            background-color: transparent;
            border-bottom: 1px solid var(--color-border-subtle);
            border-radius: 0;
            gap: var(--space-4);
            padding: 0 var(--space-4);
        }

        .chat-application .sidebar-content .tab-group.btn-group .tab-button {
            background-color: transparent !important;
            border-bottom: 2px solid transparent !important;
            border-radius: 0 !important;
            color: var(--color-text-muted) !important;
            flex: 0 0 auto;
            font-size: var(--type-label-size);
            font-weight: 500;
            margin-bottom: -1px;
            min-height: 40px;
            padding: 0 !important;
        }

        .chat-application .sidebar-content .tab-group.btn-group .tab-button.btn-outline-primary {
            border-bottom-color: transparent !important;
            color: var(--color-text-muted) !important;
        }

        .chat-application .sidebar-content .tab-group.btn-group .tab-button.btn-outline-primary:hover {
            color: var(--color-text-primary) !important;
        }

        /* The dark theme paints outline buttons in the accent; the inactive tabs and segments stay quiet there too. */
        html.dark-layout .chat-application .sidebar-content .tab-group.btn-group .tab-button.btn-outline-primary,
        html.dark-layout .chat-application .sidebar-content .channel-group .channel-button.btn-outline-primary {
            background-color: transparent !important;
            border-bottom-color: transparent !important;
            color: var(--color-text-muted) !important;
        }

        html.dark-layout .chat-application .sidebar-content .channel-group .channel-button.btn-primary {
            background-color: var(--color-surface-secondary) !important;
            color: var(--color-text-primary) !important;
        }

        html.dark-layout .chat-application .sidebar-content .tab-group.btn-group .tab-button.btn-primary {
            background-color: transparent !important;
            border-bottom-color: var(--color-primary) !important;
            color: var(--color-primary) !important;
        }

        .chat-application .sidebar-content .tab-group.btn-group .tab-button:hover {
            color: var(--color-text-primary) !important;
        }

        .chat-application .sidebar-content .tab-group.btn-group .tab-button.btn-primary {
            border-bottom-color: var(--color-primary) !important;
            color: var(--color-primary) !important;
            font-weight: 600;
        }

        .chat-application .sidebar-content > .pt-1.pb-1 {
            padding-bottom: 0 !important;
            padding-top: 0 !important;
        }

        /* Rows: 40px avatar, name + time, one preview line. */
        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row {
            align-items: center;
            border-bottom: 0;
            border-radius: var(--radius-lg);
            gap: var(--space-3);
            margin: 2px var(--space-2);
            padding: var(--space-3) var(--space-3);
            width: calc(100% - (var(--space-2) * 2));
        }

        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row.active {
            box-shadow: none;
        }

        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row .chat-info,
        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row .email-row-main {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
            padding: 0;
        }

        .conv-row-top,
        .email-row-top,
        .conv-row-bottom {
            align-items: baseline;
            display: flex;
            gap: var(--space-2);
            min-width: 0;
        }

        .conv-row-bottom {
            align-items: center;
        }

        .conv-row-top h6,
        .email-row-top h6 {
            color: var(--color-text-primary);
            flex: 1 1 auto;
            font-size: 0.9063rem;
            font-weight: 600;
            min-width: 0;
        }

        .conv-row .chat-time {
            color: var(--color-text-muted);
            flex: 0 0 auto;
            font-size: 0.75rem;
        }

        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row .conv-row-bottom .card-text,
        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row .email-row-preview {
            color: var(--color-text-secondary);
            flex: 1 1 auto;
            font-size: 0.8125rem;
            min-width: 0;
        }

        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row .conv-row-top h6,
        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row .email-row-top h6,
        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row .email-row-subject {
            color: var(--color-text-primary);
        }

        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row .chat-time {
            color: var(--color-text-muted);
        }

        /* The selected row is one soft surface: no per-element tint bands inside it. */
        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row.active h6,
        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row.active .card-text,
        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row.active .chat-time {
            background: transparent !important;
        }

        .conv-row-bottom .conversation-channel-badge {
            flex: 0 0 auto;
        }

        .conv-row-bottom .chat-meta {
            flex: 0 0 auto;
        }

        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row.conv-unread h6,
        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row.conv-unread .card-text {
            color: var(--color-text-primary);
            font-weight: 700;
        }

        .conv-row .conversation-row-avatar {
            background: var(--color-primary-soft-bg);
            color: var(--color-primary);
            font-size: 0.8125rem;
            font-weight: 700;
        }

        .chat-application .sidebar-content .chat-user-list-wrapper li.conv-row.active .conversation-row-avatar {
            background: var(--color-surface) !important;
        }

        .email-row-subject {
            color: var(--color-text-primary);
            font-size: 0.8125rem;
            font-weight: 600;
        }

        .email-row-preview {
            color: var(--color-text-secondary);
            font-size: 0.8125rem;
        }

        .email-row-count {
            background: var(--color-surface-secondary);
            border-radius: var(--radius-full);
            color: var(--color-text-secondary);
            font-size: 0.6875rem;
            font-weight: 600;
            margin-left: var(--space-1);
            padding: 0 var(--space-2);
        }

        /* ── Conversation header ── */
        .chat-application .chat-app-window .active-chat .chat-header {
            gap: var(--space-3);
            min-height: 65px;
            padding: var(--space-3) var(--space-5);
        }

        .conversation-header-text {
            flex: 1 1 auto;
            gap: var(--space-3);
        }

        .conversation-header-avatar {
            align-items: center;
            color: var(--color-primary);
            display: inline-flex;
            flex: 0 0 auto;
            font-size: 0.8125rem;
            font-weight: 700;
            height: 40px;
            justify-content: center;
            width: 40px;
        }

        .conversation-header-avatar:empty {
            display: none;
        }

        .conversation-header-id {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .conversation-header-id h6 {
            color: var(--color-text-primary);
            font-size: 1.0313rem;
            font-weight: 700;
        }

        .conversation-header-sub {
            color: var(--color-text-muted);
            font-size: 0.78rem;
        }

        .conversation-header-sub:empty {
            display: none;
        }

        .conversation-header-actions {
            flex: 0 0 auto;
            gap: var(--space-2);
        }

        .conv-hb,
        .chat-header .add-to-blacklist,
        .chat-header .remove-btn,
        .chat-header .add-to-pin {
            align-items: center;
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            color: var(--color-text-primary);
            display: inline-flex;
            height: 40px;
            justify-content: center;
            padding: 0;
            width: 40px;
        }

        .conv-hb[hidden] {
            display: none;
        }

        .conv-hb:hover,
        .chat-header .add-to-blacklist:hover,
        .chat-header .remove-btn:hover,
        .chat-header .add-to-pin:hover {
            background: var(--color-row-hover);
        }

        .chat-header .add-to-pin:empty {
            display: none;
        }

        .chat-application .chat-app-window .active-chat .chat-header .cursor-pointer,
        .chat-header .conv-hb svg {
            height: 19px;
            margin: 0 !important;
            padding: 0;
            width: 19px;
        }

        /* ── Timeline: one native scroll container ── */
        .chat-application .chat-app-window .conversation-main .user-chats {
            overflow-x: hidden !important;
            overflow-y: auto !important;
            padding: var(--space-5) var(--space-6);
            scrollbar-gutter: stable;
        }

        .user-chats .ps__rail-x,
        .user-chats .ps__rail-y {
            display: none !important;
        }

        .timeline-divider {
            color: var(--color-text-muted);
            font-size: 0.75rem;
            font-weight: 600;
            justify-content: center;
            margin: var(--space-3) 0;
        }

        .timeline-divider::before,
        .timeline-divider::after {
            display: none;
        }

        /* SMS: familiar left/right bubbles, time and delivery state beneath. */
        .chat-application .chat-app-window .chats .timeline-message.chat {
            display: flex;
            margin: 0 0 var(--space-3);
        }

        .chat-application .chat-app-window .chats .timeline-message.chat .chat-body {
            align-items: flex-end;
            display: flex;
            flex-direction: column;
            margin: 0 0 0 auto;
            max-width: 70%;
            overflow: visible;
        }

        .chat-application .chat-app-window .chats .timeline-message.chat-left .chat-body {
            align-items: flex-start;
            margin: 0 auto 0 0;
        }

        .chat-application .chat-app-window .chats .timeline-message .chat-body .chat-content {
            border-radius: var(--radius-xl) var(--radius-xl) 6px var(--radius-xl);
            float: none;
            font-size: 0.9063rem;
            line-height: 1.45;
            margin: 0;
            max-width: 100%;
            overflow-wrap: anywhere;
            padding: 10px 14px;
        }

        .chat-application .chat-app-window .chats .timeline-message.chat-left .chat-body .chat-content {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-xl) var(--radius-xl) var(--radius-xl) 6px;
        }

        .chat-application .chat-app-window .chats .timeline-message .chat-content p {
            margin: 0;
        }

        .chat-application .chat-app-window .chats .timeline-message .chat-content p + p {
            margin-top: var(--space-2);
        }

        .timeline-message-foot {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-2);
            margin-top: 4px;
        }

        .chat-application .chat-app-window .chats .timeline-message .timeline-message-foot .timeline-message-meta {
            color: var(--color-text-muted);
            font-size: 0.72rem;
            margin: 0;
            opacity: 1;
        }

        .timeline-message-warning .timeline-message-foot .timeline-message-meta {
            color: var(--color-status-warning-text);
        }

        /* Email: a card per message — sender header, subject, body that wraps and uses the whole width. */
        .email-message {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            clear: both;
            margin: 0 0 var(--space-3);
            max-width: none;
            overflow: hidden;
            padding: 0 0 var(--space-4);
        }

        .email-message-head {
            align-items: center;
            display: flex;
            gap: var(--space-3);
            padding: 14px 18px 10px;
        }

        .email-message-avatar {
            align-items: center;
            background: var(--color-primary-soft-bg);
            border-radius: var(--radius-full);
            color: var(--color-primary);
            display: inline-flex;
            flex: 0 0 auto;
            font-size: 0.78rem;
            font-weight: 700;
            height: 36px;
            justify-content: center;
            width: 36px;
        }

        .email-message-outbound .email-message-avatar {
            background: var(--color-primary);
            color: var(--color-button-text);
        }

        .email-message-from {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-width: 0;
        }

        .email-message-sender {
            color: var(--color-text-primary);
            font-size: 0.875rem;
            overflow-wrap: anywhere;
        }

        .email-message-direction,
        .email-message-time {
            color: var(--color-text-muted);
            font-size: 0.75rem;
        }

        .email-message-time {
            flex: 0 0 auto;
            white-space: nowrap;
        }

        .email-message-subject,
        .email-message-body,
        .email-message-status {
            margin-left: 66px;
            margin-right: 18px;
        }

        .email-message-subject {
            color: var(--color-text-primary);
            font-size: 0.9375rem;
            font-weight: 600;
            margin-bottom: var(--space-2);
            margin-top: 0;
            overflow-wrap: anywhere;
        }

        .email-message-body {
            color: var(--color-text-primary);
            font-size: 0.9063rem;
            line-height: 1.55;
            margin-bottom: 0;
            margin-top: 0;
            max-width: none;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .email-message-status {
            color: var(--color-text-muted);
            font-size: 0.78rem;
            margin-bottom: 0;
            margin-top: var(--space-3);
        }

        .email-message.timeline-message-warning .email-message-status {
            background: var(--color-status-warning-soft-bg);
            border-radius: var(--radius-md);
            color: var(--color-status-warning-text);
            padding: 10px 12px;
        }

        @media (max-width: 575.98px) {
            .email-message-subject,
            .email-message-body,
            .email-message-status {
                margin-left: 18px;
            }

            .chat-application .chat-app-window .conversation-main .user-chats {
                padding: var(--space-4);
            }

            .chat-application .chat-app-window .chats .timeline-message.chat .chat-body {
                max-width: 88%;
            }
        }

        /* ── Composer ── */
        .chat-application .chat-app-window .chat-app-form {
            align-items: flex-end;
            background: var(--color-surface);
            border-top: 1px solid var(--color-border-subtle);
            gap: var(--space-3);
            padding: var(--space-3) var(--space-5) var(--space-4);
        }

        .chat-application .chat-app-window .chat-app-form .form-send-message {
            border-radius: var(--radius-lg);
        }

        .chat-app-form .input-group-merge .form-control,
        .chat-app-form .input-group-merge .input-group-text {
            background: transparent;
            border-color: var(--color-input-border);
        }

        .chat-app-form .input-group-merge {
            border: 1px solid var(--color-input-border);
            border-radius: var(--radius-lg);
            min-height: 48px;
            overflow: hidden;
        }

        .chat-app-form .input-group-merge .form-control,
        .chat-app-form .input-group-merge .input-group-text {
            border: 0;
        }

        .chat-app-form .input-group-merge:focus-within {
            border-color: var(--color-focus-border);
            box-shadow: 0 0 0 0.2rem var(--focus-ring-color);
        }

        textarea.message {
            border: 0;
            box-shadow: none !important;
            height: 46px;
            overflow-y: auto;
            white-space: pre-wrap;
        }

        .chat-app-form .send {
            border-radius: var(--radius-md);
            min-height: 48px;
            padding: 0 var(--space-5);
        }

        /* Email reply: To / Subject rows, a roomy writing area, Send at the end. */
        .composer-email-fields {
            background: var(--color-surface);
            border: 1px solid var(--color-input-border);
            border-bottom: 0;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            flex-direction: column;
            gap: 0;
            padding: 0 var(--space-4);
        }

        .composer-email-fields[hidden],
        .composer-email-to[hidden] {
            display: none;
        }

        .composer-email-to {
            align-items: center;
            border-bottom: 1px solid var(--color-border-subtle);
            display: flex;
            gap: var(--space-3);
            min-height: 40px;
        }

        .composer-email-label {
            color: var(--color-text-muted);
            flex: 0 0 56px;
            font-size: 0.8125rem;
        }

        .composer-email-recipient {
            background: var(--color-surface-secondary);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            font-size: 0.78rem;
            max-width: 100%;
            overflow-wrap: anywhere;
            padding: 3px 10px;
        }

        .composer-email-fields .form-control,
        .composer-email-fields .form-select {
            background: transparent;
            border: 0;
            border-bottom: 1px solid var(--color-border-subtle);
            border-radius: 0;
            box-shadow: none;
            min-height: 40px;
            padding-left: 0;
            padding-right: 0;
        }

        .composer-email-fields .form-control:last-child {
            border-bottom: 0;
        }

        .chat-app-form.is-email-channel .composer-email-fields {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .chat-app-form.is-email-channel .conversation-channel-switch {
            flex: 0 0 100%;
            margin: 0;
            order: -2;
        }

        .chat-app-form.is-email-channel .composer-email-fields {
            order: -1;
        }

        .chat-app-form.is-email-channel .form-send-message.input-group-merge {
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
            flex: 0 0 100%;
            margin-right: 0 !important;
            margin-top: calc(var(--space-3) * -1) !important;
            max-width: 100%;
        }

        .chat-app-form.is-email-channel textarea.message {
            height: auto;
            min-height: 96px;
            resize: vertical;
        }

        .chat-app-form.is-email-channel .send {
            margin-left: auto;
        }

        .chat-app-form.is-email-channel .attachment-icon {
            display: none;
        }

        /* ── Contact panel ── */
        .conversation-context {
            flex: 0 0 300px;
            padding: var(--space-6) var(--space-5);
            width: 300px;
        }

        .conversation-context-person {
            align-items: center;
            border-bottom: 0;
            display: flex;
            flex-direction: column;
            gap: var(--space-2);
            margin-bottom: var(--space-4);
            padding-bottom: 0;
        }

        .conversation-context-person h5,
        .conversation-context-person h6 {
            color: var(--color-text-primary);
            font-size: 1.0625rem;
            font-weight: 700;
            margin: var(--space-1) 0 0 !important;
            overflow-wrap: anywhere;
        }

        .conversation-context-avatar {
            background: var(--color-primary-soft-bg);
            color: var(--color-primary);
            font-size: 1.375rem;
            font-weight: 700;
            height: 72px;
            width: 72px;
        }

        .conversation-context-avatar .avatar-content {
            font-size: inherit;
            height: 100%;
            width: 100%;
        }

        .conversation-context-actions {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-2);
            margin-bottom: var(--space-4);
        }

        .conversation-context-actions > * {
            align-items: center;
            display: inline-flex;
            flex: 1 1 0;
            justify-content: center;
            min-height: 40px;
            white-space: nowrap;
        }

        .conv-btn {
            align-items: center;
            border-radius: var(--radius-md);
            display: inline-flex;
            font-size: 0.875rem;
            font-weight: 600;
            gap: var(--space-2);
            justify-content: center;
            padding: 0 var(--space-4);
        }

        .conv-btn[hidden] {
            display: none;
        }

        .conv-btn-outline {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            color: var(--color-text-primary);
        }

        .conv-btn-outline:hover {
            background: var(--color-row-hover);
        }

        .conversation-context-facts {
            column-gap: var(--space-3);
            row-gap: 0;
        }

        .conversation-context-facts dt,
        .conversation-context-facts dd {
            align-items: center;
            border-top: 1px solid var(--color-border-subtle);
            display: flex;
            min-height: 38px;
        }

        .conversation-context-facts dt:first-of-type,
        .conversation-context-facts dt:first-of-type + dd {
            border-top: 0;
        }

        .conversation-context-facts dd {
            font-weight: 600;
            justify-content: flex-end;
            text-align: right;
        }

        .conversation-context [data-context="email"] .conversation-context-details dt {
            margin-top: var(--space-3);
        }

        /* Give Recents / Unread / Read / All equal-sized clickable columns. */
        .chat-application .sidebar-content .tab-group.btn-group {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0;
            width: 100%;
            box-sizing: border-box;
        }
        .chat-application .sidebar-content .tab-group.btn-group .tab-button {
            align-items: center;
            display: flex;
            flex: none;
            justify-content: center;
            min-width: 0;
            width: 100%;
            padding: 0 var(--space-1) !important;
        }

        /* Compose inside the remaining conversation width, never outside it. */
        .chat-application .chat-app-window .chat-app-form:not(.is-email-channel) {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(106px, 138px) auto;
            /* Dense: the template picker and Send backfill beside the message field even when the attachment chip sits between them in the DOM. */
            grid-auto-flow: row dense;
            align-items: center;
            box-sizing: border-box;
            min-width: 0;
            width: 100%;
            max-width: 100%;
            gap: var(--space-2);
        }
        .chat-app-form:not(.is-email-channel) .form-send-message {
            grid-column: 1;
            flex: none;
            min-width: 0;
            max-width: 100%;
            width: 100%;
            margin: 0 !important;
        }
        .chat-app-form:not(.is-email-channel) .composer-template-select {
            grid-column: 2;
            min-width: 0;
            width: 100%;
            margin: 0 !important;
        }
        .chat-app-form .composer-template-select .select2-container {
            width: 100% !important;
            max-width: 100%;
            min-width: 0;
        }
        .chat-app-form:not(.is-email-channel) .send {
            grid-column: 3;
            margin: 0;
            white-space: nowrap;
        }
        .chat-app-form:not(.is-email-channel) .conversation-channel-switch:not([hidden]) {
            grid-column: 1 / -1;
            margin: 0;
        }
        .chat-app-form:not(.is-email-channel) .composer-guidance {
            grid-column: 1 / -1;
        }
        .chat-app-form .conversation-channel-switch[hidden],
        .composer-attachment-selected[hidden] {
            display: none !important;
        }
        .composer-attachment-trigger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            background: transparent;
            color: var(--color-text-secondary);
            cursor: pointer;
            padding: var(--space-2);
        }
        .composer-attachment-trigger:hover,
        .composer-attachment-trigger:focus-visible { color: var(--color-primary); }
        .composer-attachment-trigger:disabled { cursor: not-allowed; opacity: .45; }
        .composer-attachment-selected {
            display: flex;
            grid-column: 1 / -1;
            gap: var(--space-2);
            align-items: center;
            min-width: 0;
            color: var(--color-text-secondary);
            font-size: var(--type-caption-size);
        }
        .composer-attachment-selected #media-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .composer-attachment-selected button {
            border: none;
            background: none;
            color: var(--color-primary);
            flex: none;
        }
        .chat-app-form.is-email-channel .composer-attachment-trigger,
        .chat-app-form.is-email-channel #media-selection,
        .chat-app-form.is-email-channel .form-send-message > .input-group-text {
            display: none !important;
        }
        .chat-header .add-to-pin[hidden] { display: none !important; }
        .chat-header .add-to-pin.is-pinned { color: var(--color-primary); }
        .chat-header .add-to-pin:disabled { opacity: .6; cursor: progress; }
        .conversation-context-profile-link { color: inherit; text-decoration: none; }
        .conversation-context-profile-link:hover { color: var(--color-primary); text-decoration: underline; }
        /* At intermediate browser widths the CRM sidebar and contact panel leave less room than the viewport suggests. */
        @media (min-width: 551px) and (max-width: 1399px) {
            .chat-application .chat-app-window .chat-app-form:not(.is-email-channel) {
                grid-template-columns: minmax(0, 1fr) auto;
            }
            .chat-app-form:not(.is-email-channel) .form-send-message { grid-column: 1 / -1; }
            .chat-app-form:not(.is-email-channel) .composer-template-select { grid-column: 1; }
            .chat-app-form:not(.is-email-channel) .send { grid-column: 2; }
        }
        @media (max-width: 550px) {
            .chat-application .chat-app-window .chat-app-form:not(.is-email-channel) {
                grid-template-columns: minmax(0, 1fr) auto;
            }
            .chat-app-form:not(.is-email-channel) .form-send-message {
                grid-column: 1 / -1;
            }
            .chat-app-form:not(.is-email-channel) .composer-template-select {
                grid-column: 1;
                max-width: 100%;
            }
            .chat-app-form:not(.is-email-channel) .send {
                grid-column: 2;
            }
        }
    </style>

@endsection

@section('content-sidebar')
    @include('customer.ChatBox._sidebar')
@endsection


@section('content')
    {{-- The shell shows no page title for the inbox; keep the heading for assistive tech only. --}}
    <h1 class="visually-hidden">{{ __('locale.menu.Conversations') }}</h1>
    <div class="body-content-overlay"></div>
    <!-- Main chat area -->
    <section class="chat-app-window">
        <!-- To load Conversation -->
        <div class="start-chat-area">
            <div class="mb-1 start-chat-icon">
                <x-ds-icon name="message-square" />
            </div>
            <h4 class="sidebar-toggle start-chat-text d-block d-md-none">
                {{ __('locale.labels.new_conversion') }}
            </h4>
            <h4 class="sidebar-toggle start-chat-text d-none d-md-block">
                No conversation selected
            </h4>
            <p class="start-chat-description d-none d-md-block">Choose a conversation on the left, or start a new one.</p>
            <a href="{{ route('customer.workspaces.businesses.conversations.new', [$workspaceUid, $businessUid]) }}"
               class="btn btn-primary btn-sm d-none d-md-inline-flex">{{ __('locale.labels.new_conversion') }}</a>
        </div>
        <!--/ To load Conversation -->

        <!-- Active Chat -->
        <div class="active-chat d-none">
            <!-- Chat Header -->
            <div class="chat-navbar">
                <header class="chat-header">
                    <div class="d-flex align-items-center conversation-header-text">
                        <div class="sidebar-toggle d-block d-lg-none me-1">
                            <x-ds-icon name="menu" class="font-medium-5" />
                        </div>
                        <span class="avatar bg-light-primary conversation-header-avatar" data-role="conversation-avatar" aria-hidden="true"></span>
                        <div class="conversation-header-id">
                            <h6 class="mb-0 text-truncate" data-role="conversation-title"></h6>
                            <span class="conversation-header-sub text-truncate" data-role="conversation-subtitle"></span>
                        </div>
                        <button type="button" class="add-to-pin conv-hb" aria-label="Pin conversation" title="Pin conversation" aria-pressed="false" hidden><x-ds-icon name="pin" size="18" aria-hidden="true" /></button>
                    </div>
                    <div class="d-flex align-items-center conversation-header-actions">

                        {{-- Shown only when the open conversation resolved to a verified contact this user may call AND the existing
                             browser softphone is on the page; its identity is set per conversation by JS and cleared on every switch. --}}
                        <button type="button" class="conv-hb conversation-call-btn" data-role="conversation-call" aria-label="Call" title="Call" hidden>
                            <x-ds-icon name="phone" aria-hidden="true" />
                        </button>

                        <x-tooltip text="{{ __('locale.labels.block') }}" placement="top" class="add-to-blacklist">
                            <x-ds-icon name="shield" class="cursor-pointer font-medium-2 mx-1 text-primary" />
                        </x-tooltip>

                        <x-tooltip text="{{ __('locale.buttons.delete') }}" placement="top" class="remove-btn">
                            <x-ds-icon name="trash" class="cursor-pointer font-medium-2 text-danger" />
                        </x-tooltip>

                        {{-- Below xl the contact panel slides over the timeline; this opens it. --}}
                        <span class="d-xl-none">
                            <x-button variant="ghost" size="sm" class="conversation-context-toggle ms-1" icon="panel-right"
                                      aria-label="Contact details" aria-controls="conversation-context" aria-expanded="false" />
                        </span>

                    </div>
                </header>
            </div>
            <!--/ Chat Header -->

            <div class="conversation-body">
                <div class="conversation-main">
                    <!-- The person's activity timeline: messages and what happened around them -->
                    <div class="user-chats">
                        <div class="chats">
                            <div class="chat_history" data-role="conversation-timeline"></div>
                        </div>
                    </div>
                    <!--/ The person's activity timeline -->

                    <!-- Submit Chat form -->
                    <form class="chat-app-form" action="javascript:void(0);" onsubmit="enter_chat();" autocomplete="off">
                        <input type="hidden" value="" name="chat_id" class="chat_id">

                        {{-- The channels this person can actually be reached on (set per conversation from the server's composer options):
                             one option when only one is usable, both when both are. Never switched silently. --}}
                        <div class="conversation-channel-switch" data-role="composer-channel" role="group" aria-label="Send as">
                            <button type="button" class="conversation-channel-option is-active" data-channel="sms" aria-pressed="true">SMS</button>
                            <button type="button" class="conversation-channel-option" data-channel="email" aria-pressed="false" hidden>Email</button>
                        </div>
                        <div class="composer-email-fields" data-role="composer-email-fields" hidden>
                            <div class="composer-email-to" data-role="composer-email-to"><span class="composer-email-label">To</span><span class="composer-email-recipient" data-role="composer-email-recipient"></span></div>
                            <input type="text" id="email-subject" class="form-control form-control-sm" maxlength="200" placeholder="Subject" aria-label="Email subject" autocomplete="off">
                            <select id="email-location" class="form-select form-select-sm" aria-label="Which location is this for?" hidden></select>
                        </div>
                        <p class="composer-guidance text-caption mb-0" data-role="composer-guidance" hidden></p>

                        <div class="input-group input-group-merge me-1 form-send-message">
                            <textarea id="message" class="form-control message" placeholder="Write a message" autocomplete="off" aria-label="Message"></textarea>

                            <span class="input-group-text">
                                <button type="button" id="media-picker" class="composer-attachment-trigger" title="Attach photo or video" aria-label="Attach photo or video">
                                    <x-ds-icon name="image" id="mms-icon" aria-hidden="true" />
                                </button>
                                <input type="file" id="media_image" name="media_image" accept=".jpg,.jpeg,.png,.gif,.bmp,.webp,.mp4,.mov,.ogg,.qt" hidden />
                            </span>

                        </div>
                        <div id="media-selection" class="composer-attachment-selected" role="status" aria-live="polite" hidden>
                            <x-ds-icon name="paperclip" size="14" aria-hidden="true" />
                            <span id="media-name"></span>
                            <button type="button" id="media-remove" aria-label="Remove attachment">Remove</button>
                        </div>

                        <div class="composer-template-select me-1">
                            <select class="form-select select2" id="sms_template" data-placeholder="Select Template">
                                <option value="0">Select Template</option>
                                @foreach($templates as $template)
                                    <option value="{{$template->id}}">{{ $template->name }}</option>
                                @endforeach
                            </select>
                        </div>


                        <x-button variant="primary" class="send" onclick="enter_chat();">
                            <x-ds-icon name="send" class="d-lg-none" />
                            <span class="d-none d-lg-block">{{ __('locale.buttons.send') }}</span>
                        </x-button>
                    </form>
                    <!--/ Submit Chat form -->
                </div>

                <!-- Contact panel -->
                <aside id="conversation-context" class="conversation-context" data-role="conversation-context" aria-label="Contact details"></aside>
                <!--/ Contact panel -->
            </div>
        </div>
        <!--/ Active Chat -->
    </section>
    <!--/ Main chat area -->

    {{-- The existing CRM browser softphone (popup + SDK), only when this user may place browser calls here. No second calling system. --}}
    @includeWhen(! empty($browserCalling), 'customer.people._softphone')
@endsection

@section('vendor-script')
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection


@section('page-script')
    <!-- Page js files -->
    <script src="{{ asset(mix('js/scripts/pages/chat.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/extensions/sweetalert2.all.min.js')) }}"></script>
    @if(config('broadcasting.connections.pusher.app_id'))
        <script src="{{ asset(mix('js/scripts/echo.js')) }}"></script>
    @endif

    <script>
      // The workspace fills exactly what the shell leaves: below the navbar,
      // above the footer. Both are measured, not assumed, so the navbar and
      // footer types (static / sticky / fixed / hidden) and any font scale
      // size it correctly. The CSS reads --conversations-top (clearance for a
      // fixed navbar) and --conversations-chrome (everything that is not the
      // workspace); its own fallbacks apply until this runs.
      (function () {
        const root = document.documentElement;
        const wrapper = document.querySelector('.app-content.chat-application .content-area-wrapper');
        if (!wrapper) return;
        const navbar = document.querySelector('.header-navbar');
        const footer = document.querySelector('footer.footer');

        let last = '';
        const syncChrome = function (pass) {
          const fixedNav = navbar && getComputedStyle(navbar).position === 'fixed';
          root.style.setProperty('--conversations-top', fixedNav ? navbar.offsetHeight + 'px' : '0px');
          const top = wrapper.getBoundingClientRect().top + window.pageYOffset;
          const footerHeight = footer && getComputedStyle(footer).display !== 'none' ? footer.getBoundingClientRect().height : 0;
          const chrome = (top + footerHeight).toFixed(2) + 'px';
          root.style.setProperty('--conversations-chrome', chrome);
          // The clearance above shifts the top that was just measured; go again
          // until it is stable (one extra pass at most in practice).
          if (chrome !== last && pass < 3) {
            last = chrome;
            syncChrome(pass + 1);
          }
        };
        const run = function () { syncChrome(0); };

        run();
        window.addEventListener('load', run);
        window.addEventListener('resize', run);
        window.addEventListener('orientationchange', run);
        if (window.ResizeObserver) {
          const observer = new ResizeObserver(run);
          [navbar, footer].forEach(function (el) { if (el) observer.observe(el); });
        }
      })();
    </script>

    <script>
      // Customer Experience Redesign Slice 2B — every endpoint is rendered by
      // the server from the Business-scoped route family, so the browser never
      // assembles a /chat-box/* path of its own. `__UID__` is replaced with
      // the conversation's public uid; no numeric id is ever used.
      const conversationRoutes = {
        timeline: "{{ route('customer.workspaces.businesses.conversations.timeline', [$workspaceUid, $businessUid, '__UID__']) }}",
        notification: "{{ route('customer.workspaces.businesses.conversations.notification', [$workspaceUid, $businessUid, '__UID__']) }}",
        reply: "{{ route('customer.workspaces.businesses.conversations.reply', [$workspaceUid, $businessUid, '__UID__']) }}",
        retry: "{{ route('customer.workspaces.businesses.conversations.retry', [$workspaceUid, $businessUid, '__UID__']) }}",
        delete: "{{ route('customer.workspaces.businesses.conversations.delete', [$workspaceUid, $businessUid, '__UID__']) }}",
        block: "{{ route('customer.workspaces.businesses.conversations.block', [$workspaceUid, $businessUid, '__UID__']) }}",
        pin: "{{ route('customer.workspaces.businesses.conversations.pin', [$workspaceUid, $businessUid, '__UID__']) }}",
        load: "{{ route('customer.workspaces.businesses.conversations.load', [$workspaceUid, $businessUid]) }}",
        emailThread: "{{ route('customer.workspaces.businesses.conversations.email_thread', [$workspaceUid, $businessUid]) }}",
      };
      const conversationUrl = (name, uid) => conversationRoutes[name].replace('__UID__', encodeURIComponent(uid));

      // autoscroll to bottom of Chat area
      let chatContainer = $(".user-chats"),
        details,
        chatHistory = $(".chat_history");

      // Basic Select2 select
      $(".select2").each(function() {
        let $this = $(this);
        $this.wrap("<div class=\"position-relative\"></div>");
        $this.select2({
          // the following code is used to disable x-scrollbar when click in select input and
          // take 100% width in responsive also
          dropdownAutoWidth: true,
          width: "100%",
          dropdownParent: $this.parent(),
          placeholder: $this.data("placeholder")
        });
      });


      $("#sms_template").on("change", function() {

        let template_id = $(this).val(),
          $get_msg = $("#message");

        if (template_id === "0") {
          return false;
        }

        $.ajax({
          // B1's Business-scoped template fetch: a template belonging to
          // another Business is simply not found there.
          url: "{{ route('customer.workspaces.businesses.outreach.templates.show_data', [$workspaceUid, $businessUid, '__ID__']) }}".replace('__ID__', encodeURIComponent(template_id)),
          type: "POST",
          data: {
            _token: "{{csrf_token()}}"
          },
          cache: false,
          success: function(data) {
            if (data.status === "success") {
              const caretPos = $get_msg[0].selectionStart;
              const textAreaTxt = $get_msg.val();
              let txtToAdd = data.message;

              $get_msg.val(textAreaTxt.substring(0, caretPos) + txtToAdd + textAreaTxt.substring(caretPos)).val().length;

            } else {
              toastr["warning"](data.message, "{{ __('locale.labels.attention') }}", {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            }
          },
          error: function(reject) {
            if (reject.status === 422) {
              let errors = reject.responseJSON.errors;
              $.each(errors, function(key, value) {
                toastr["warning"](value[0], "{{__('locale.labels.attention')}}", {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              });
            } else {
              toastr["warning"](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            }
          }
        });
      });


      // ── Unified inbox composer: SMS and Email on the same conversation ──────────────────────────────────────────────
      // The server says which channels are usable (composer options); this only shows them. Email posts to the EXISTING
      // Settings → Email send/reply endpoints, SMS to the existing reply endpoint. A channel is never switched silently.
      var composerState = { sms: true, email: false, channel: "sms", cfg: null };
      var pendingEmailTokens = {};

      function applyComposer(c, defaultChannel) {
        c = c || { sms: true, email: false, default: "sms" };
        // A refresh of the SAME open conversation (e.g. a new message arrived) keeps the channel and the typed subject.
        const sameConversation = composerState.forId === String($(".chat_id").val());
        const keepChannel = sameConversation && ((composerState.channel === "email" && c.email) || (composerState.channel === "sms" && c.sms)) ? composerState.channel : null;
        composerState.forId = String($(".chat_id").val());
        if (!sameConversation) { $("#email-subject").val(""); }
        composerState.cfg = c;
        const $switch = $("[data-role=composer-channel]");
        $switch.find("[data-channel=sms]").prop("hidden", !c.sms);
        $switch.find("[data-channel=email]").prop("hidden", !c.email);
        $switch.attr("data-single", c.sms && c.email ? "0" : "1");
        // A single available channel needs no SMS/Email pill beside the writing field.
        $switch.prop("hidden", !(c.sms && c.email));

        const $locations = $("#email-location").empty();
        (c.locations || []).forEach(function(l) {
          $locations.append($("<option></option>").val(l.uid).text(l.name));
        });
        if ((c.locations || []).length) {
          $locations.prepend($("<option></option>").val("").text("Which location is this for?")).val("");
        }
        $locations.prop("hidden", !(c.locations || []).length);

        setComposerChannel(keepChannel || c.default || defaultChannel || "sms");
      }

      function setComposerChannel(channel) {
        const c = composerState.cfg || {};
        composerState.channel = channel;
        const email = channel === "email";
        const usable = email ? !!c.email : !!c.sms;

        $("[data-role=composer-channel] .conversation-channel-option").each(function() {
          const on = $(this).data("channel") === channel;
          $(this).toggleClass("is-active", on).attr("aria-pressed", on ? "true" : "false");
        });
        $(".chat-app-form").toggleClass("is-email-channel", email);
        $("[data-role=composer-email-fields]").prop("hidden", !(email && usable));
        $("[data-role=composer-email-recipient]").text(c.recipient || "");
        $("[data-role=composer-email-to]").prop("hidden", !c.recipient);
        if (email && usable && !$("#email-subject").val()) {
          $("#email-subject").val(c.subject || "");
        }

        // Guidance instead of a silent switch: an unusable selected channel is disabled and explained.
        let guidance = "";
        if (!usable) {
          guidance = email
            ? (c.email_connected ? "A reply isn't possible for this message." : "Email isn't connected. Connect your mailbox in Settings → Email to reply by email.")
            : "";
        } else if (!email && c.email_guidance) {
          guidance = "Email isn't connected. Connect your mailbox in Settings → Email to also reach this person by email.";
        }
        const $guide = $("[data-role=composer-guidance]").prop("hidden", guidance === "").empty();
        if (guidance !== "") {
          $guide.text(guidance + " ");
          if (c.settings_url) {
            $guide.append($("<a></a>").attr("href", c.settings_url).text("Open Email settings"));
          }
        }
        syncSendAvailability();
        $("#message").attr("placeholder", email ? "Write your email" : "Write a message");
      }

      $(document).on("click", "[data-role=composer-channel] .conversation-channel-option", function() {
        if ($("[data-role=composer-channel]").attr("data-single") === "1") {
          return;
        }
        setComposerChannel($(this).data("channel"));
      });

      // The idempotency key of an email send belongs to ONE intent (conversation + endpoint + exact content), never to the wording
      // of an outcome. It is kept until the server says "sent" (HTTP 200); after ANY other outcome — including a dropped connection,
      // where the provider may well have accepted the mail — a manual retry of the same content re-sends the SAME key, which the
      // existing sender turns into "already sent" instead of a second email. Edited content is a new intent and gets a new key.
      // Nothing here ever resubmits on its own.
      var emailSendsInFlight = {};

      // A pending send is remembered BY CONTENT (conversation + location + subject + body) together with the endpoint, Contact and key it
      // was FIRST submitted with. A timeline refresh after an ambiguous outcome can legitimately change the composer's own endpoint
      // (a new-email /send becomes a /reply once the message exists); the retry must still go to the ORIGINAL endpoint with the ORIGINAL
      // key, because the sender's idempotency is per endpoint+key — a /reply with the old key would be a different operation.
      // The Location is part of the pending record, NOT of the key: the selector is cleared by the refresh that follows an ambiguous send,
      // and the retry must still carry the Location the original was submitted with.
      // The intent is scoped to the Workspace and Business this page serves: provider thread ids (IMAP ones derive from the root Message-ID)
      // can repeat across Businesses, and a pending record carries an endpoint, Contact, Location and token that must never be reused
      // for another Business. The endpoint itself is NOT part of the identity (it changes from /send to /reply on refresh).
      const emailIntentScope = ["{{ $workspaceUid }}", "{{ $businessUid }}"];
      const emailPendingPrefix = "mg.emailPending.v2.";

      function emailIntentKey(chatId, subject, body) {
        return JSON.stringify([emailIntentScope[0], emailIntentScope[1], String(chatId), subject, body]);
      }

      function emailTokenStore() {
        try { return window.sessionStorage; } catch (e) { return null; }
      }

      // Records written before pending sends were Business-scoped (the old "mg.emailPending." keys) cannot be attributed to a Business,
      // so they are never trusted: dropped, never restored.
      (function dropUnscopedPendingEmails() {
        const store = emailTokenStore();
        if (!store) { return; }
        try {
          Object.keys(store).forEach(function(k) {
            if (k.indexOf("mg.emailPending.") === 0 && k.indexOf(emailPendingPrefix) !== 0) { store.removeItem(k); }
          });
        } catch (e) { /* storage unavailable */ }
      })();

      function peekPendingEmail(intent) {
        let pending = pendingEmailTokens[intent];
        const store = emailTokenStore();
        if (!pending && store) {
          try { pending = JSON.parse(store.getItem(emailPendingPrefix + intent) || "null"); } catch (e) { pending = null; }
        }
        return pending && pending.token && pending.url ? pending : null;
      }

      function pendingEmailFor(intent, c, locationUid) {
        let pending = peekPendingEmail(intent);
        if (!pending) {
          pending = { token: m5UuidV4(), url: c.url, contact_uid: c.contact_uid || "", location: locationUid || "" };
        }
        pendingEmailTokens[intent] = pending;
        const store = emailTokenStore();
        if (store) { try { store.setItem(emailPendingPrefix + intent, JSON.stringify(pending)); } catch (e) { /* storage unavailable: the in-memory record still holds */ } }
        return pending;
      }

      function forgetEmailToken(intent) {
        delete pendingEmailTokens[intent];
        const store = emailTokenStore();
        if (store) { try { store.removeItem(emailPendingPrefix + intent); } catch (e) { /* nothing to remove */ } }
      }

      // The configuration in composerState belongs to ONE conversation. The moment the selection changes it is stale: nothing may be sent
      // from it, and nothing may be sent at all until a timeline load for the NEW selection has applied its own configuration
      // (a failed load leaves the composer disabled).
      function invalidateComposer() {
        composerState.cfg = null;
        composerState.forId = null;
        $("[data-role=composer-guidance]").prop("hidden", true).empty();
        syncSendAvailability();
      }

      function composerReadyForCurrentConversation() {
        return composerState.cfg !== null && composerState.forId === String($(".chat_id").val());
      }

      // Send/typing availability for the conversation that is open NOW and its selected channel (never a stale in-flight one).
      function syncSendAvailability() {
        const c = composerState.cfg || {};
        const ready = composerReadyForCurrentConversation();
        const usable = ready && (composerState.channel === "email" ? !!c.email : !!c.sms);
        const busy = !!emailSendsInFlight[String($(".chat_id").val())];
        $("#message").prop("disabled", !usable);
        $("#media-picker").prop("disabled", !usable || composerState.channel !== "sms");
        $(".send").prop("disabled", !usable || busy);
      }

      function sendEmail() {
        const c = composerState.cfg || {};
        const sendId = String($(".chat_id").val());   // the conversation this send belongs to
        const stillHere = function() { return String($(".chat_id").val()) === sendId; };
        const rawDraft = $("#message").val() || "";    // the exact draft being submitted
        const body = rawDraft.trim();
        const subject = ($("#email-subject").val() || "").trim();
        const warn = function(text) {
          toastr["warning"](text, "{{ __('locale.labels.attention') }}", { closeButton: true, positionClass: "toast-top-right", progressBar: true, newestOnTop: true, rtl: isRtl });
        };

        if (!composerReadyForCurrentConversation() || !c.email || !c.url) { warn("Email can't be sent from here right now."); return; }
        if (subject === "" || body === "") { warn("Add a subject and a message."); return; }
        const intent = emailIntentKey(sendId, subject, body);
        // A pending (ambiguous) send of this exact content already carries its Location: the selector needs no second choice.
        if (!peekPendingEmail(intent) && (c.locations || []).length && !$("#email-location").val()) { warn("Choose which location this is for."); return; }
        if (emailSendsInFlight[sendId]) { return; }

        const pending = pendingEmailFor(intent, c, $("#email-location").val() || "");
        const data = { _token: "{{ csrf_token() }}", subject: subject, body: body, send_token: pending.token };
        if (pending.location) { data.location_uid = pending.location; }
        if (pending.contact_uid) { data.contact_uid = pending.contact_uid; }

        emailSendsInFlight[sendId] = true;
        syncSendAvailability();

        $.ajax({ url: pending.url, type: "POST", data: data, dataType: "json", headers: { Accept: "application/json" } })
          .done(function(response) {
            // A 200 can also mean "a send under this key is still running": that is NOT confirmation. Keep the key and the draft.
            if (response.outcome === "in_progress") {
              toastr["warning"](response.message || "This email is already being sent.", "{{ __('locale.labels.attention') }}", { closeButton: true, positionClass: "toast-top-right", progressBar: true, newestOnTop: true, rtl: isRtl });
              if (stillHere()) { loadTimeline(sendId); }
              return;
            }
            forgetEmailToken(intent);
            // The updated conversation timeline is confirmation; don't interrupt every email with a toast.
            if (stillHere()) {
              // Clear only the draft that was submitted: same conversation, still on Email, and the text untouched since. A newer
              // draft (typed while the request was in flight) is never erased.
              if (composerState.channel === "email" && ($("#message").val() || "") === rawDraft) {
                $("#message").val("");
              }
              loadTimeline(sendId);
            }
          })
          .fail(function(xhr) {
            const json = xhr.responseJSON || {};
            const first = json.errors ? Object.values(json.errors)[0][0] : null;
            // Keep the key. When no server answer arrived (dropped connection, timeout, 5xx) the outcome is UNKNOWN: say so, and
            // never claim it failed or retry by ourselves.
            const answered = xhr.status >= 200 && xhr.status < 500 && xhr.status !== 0;
            // ONLY the server's machine-readable disposition may release the key: "failed_terminal" means definitively not sent AND the
            // sender will never retry this key (e.g. the connection expired), so a manual retry of the same text needs a fresh key (the
            // sender re-validates the connection, authorization and Location on it). Never inferred from the status code or the wording;
            // unconfirmed, in-progress, retryable and unknown outcomes keep the original key, endpoint, contact and Location.
            if (json.outcome === "failed_terminal") { forgetEmailToken(intent); }
            warn(first || (answered ? json.message : null) || "We couldn't confirm whether this email was sent. Check your sent mail; sending the same message again will not duplicate it.");
            if (!answered && stillHere()) { loadTimeline(sendId); }
          })
          .always(function() {
            delete emailSendsInFlight[sendId];
            syncSendAvailability();
          });
      }

      // ── Conversation header and Call ────────────────────────────────────────────────────────────────────────────────
      // The Call controls are driven by ONE server decision per loaded conversation (response.call: a verified contact this user
      // may call, or null). The identity is stamped with the conversation it was loaded for and discarded the moment the selection
      // changes, so a click can never dial the previously selected contact. The softphone itself (existing mg-softphone.js) places
      // nothing until the server re-checks the contact on its own endpoint. A control is shown only when that softphone is present.
      function setPinState(isPinned) {
        const $pin = $(".add-to-pin");
        $pin.prop("hidden", false)
          .toggleClass("is-pinned", !!isPinned)
          .attr("aria-pressed", isPinned ? "true" : "false")
          .attr("aria-label", isPinned ? "Unpin conversation" : "Pin conversation")
          .attr("title", isPinned ? "Unpin conversation" : "Pin conversation");
      }

      function softphoneAvailable() {
        return !!(window.MGSoftphone && typeof window.MGSoftphone.callContact === "function");
      }

      function resetConversationHeader() {
        $("[data-role=conversation-avatar]").text("");
        $("[data-role=conversation-subtitle]").text("");
        $(".conversation-call-btn").prop("hidden", true).removeAttr("data-call-uid data-call-conversation data-call-label data-call-phone data-call-initials data-call-for data-call-unavailable");
        $(".add-to-pin").prop("hidden", true).prop("disabled", false);
      }

      function applyConversationHeader(response) {
        const $panel = $("#conversation-context [data-role=contact-panel]");
        const title = String(response.title || "");
        const initials = String($panel.attr("data-header-initials") || "") || title.replace(/[^\p{L}\p{N}]/gu, "").slice(0, 1).toUpperCase();
        $("[data-role=conversation-avatar]").text(initials);
        $("[data-role=conversation-subtitle]").text(String($panel.attr("data-header-subtitle") || ""));

        const forId = String($(".chat_id").val());
        const call = response.call || null;
        const usable = !!call && softphoneAvailable();

        const $header = $(".conversation-call-btn");
        if (usable) {
          $header.attr({ "data-call-uid": call.uid || "", "data-call-conversation": call.conversation || "", "data-call-label": call.label, "data-call-phone": call.phone, "data-call-initials": call.initials, "data-call-for": forId, "aria-label": "Call " + call.label }).prop("hidden", false);
        } else {
          // Never silently hidden when the number is callable but calling is not: show the control with the precise reason.
          const blocked = call && !softphoneAvailable()
            ? "Calling could not load on this page. Refresh the page and try again."
            : String(response.call_unavailable || "");
          $header.removeAttr("data-call-uid data-call-conversation data-call-label data-call-phone data-call-initials data-call-for data-call-unavailable");
          if (blocked !== "") {
            $header.attr({ "data-call-unavailable": blocked, "aria-label": "Call unavailable: " + blocked, "title": blocked }).prop("hidden", false);
          } else {
            $header.removeAttr("data-call-unavailable").prop("hidden", true);
          }
        }

        // The panel's own Call button was rendered by the server for this contact; keep it only when it can work.
        $("#conversation-context [data-role=conversation-call]").each(function() {
          if (usable) {
            $(this).attr("data-call-for", forId).prop("hidden", false);
          } else {
            $(this).remove();
          }
        });
      }

      $(document).on("click", "[data-role=conversation-call]", function() {
        const $btn = $(this);
        const uid = String($btn.attr("data-call-uid") || "");
        const conversation = String($btn.attr("data-call-conversation") || "");
        const unavailable = String($btn.attr("data-call-unavailable") || "");
        if (unavailable !== "") {
          if (window.toastr) { window.toastr.warning(unavailable, "Calling unavailable"); }
          return;
        }
        // A stale or unstamped control (the selection moved on) never dials.
        if ((uid === "" && conversation === "") || String($btn.attr("data-call-for") || "") !== String($(".chat_id").val()) || !softphoneAvailable()) {
          $btn.prop("hidden", true);
          return;
        }
        const target = {
          uid: uid,
          label: String($btn.attr("data-call-label") || ""),
          phone: String($btn.attr("data-call-phone") || ""),
          initials: String($btn.attr("data-call-initials") || "")
        };
        // A conversation with no linked contact is called through the conversation itself (the server resolves its number).
        if (conversation !== "") { target.conversation = conversation; }
        window.MGSoftphone.callContact(target);
      });

      /**
       * Load the person's activity timeline and contact panel for one
       * conversation. Both arrive rendered and escaped by the server; nothing
       * here builds markup out of a message. A response for a conversation the
       * viewer has since left is ignored.
       */
      function loadTimeline(chat_id) {
        // An email thread (id "email:<thread key>") opens through the unified inbox endpoint; everything else is an SMS conversation.
        const isEmailThread = String(chat_id).indexOf("email:") === 0;
        const request = isEmailThread
          ? $.post(conversationRoutes.emailThread, { _token: "{{ csrf_token() }}", thread: String(chat_id).slice(6) })
          : $.post(conversationUrl('timeline', chat_id), { _token: "{{ csrf_token() }}" });

        return request
          .done(function(response) {
            if ($(".chat_id").val() !== String(chat_id)) {
              return;
            }

            // A thread that provably belongs to a person with an SMS conversation opens as that ONE conversation.
            if (response.conversation_uid) {
              $(".chat_id").val(response.conversation_uid);
            }

            $(".active-chat").toggleClass("is-email-thread", response.channel === "email");
            applyComposer(response.composer, response.channel === "email" ? "email" : "sms");

            setPinState(Number(response.pinned) === 1);
            
            $("[data-role=conversation-title]").text(response.title);
            $(".chat_history").html(response.timeline);
            $("#conversation-context").html(response.context);
            applyConversationHeader(response);

            // Show the active chat area
            $(".start-chat-area").addClass("d-none");  // Hide the initial "Start chat" screen
            $(".active-chat").removeClass("d-none");   // Show the chat area
            $(".counter").hide();

            const $chats = $(".user-chats");
            $chats.animate({ scrollTop: $chats[0].scrollHeight }, 400);  // Newest activity at the bottom
          })
          .fail(function(xhr, status, error) {
            console.error("Error loading conversation:", error);
          });
      }


      $(document).ready(function() {
        // Use event delegation to bind click events to dynamically loaded users
        $("#users-list").on("click", ".chat-users-list li, .chat-users-list-pinned li", function() {
          // Destroy + recreate textarea to prevent browser restoring value
          let msg = $("#message");
          msg.replaceWith(msg.clone().val(""));
          $("#media_image").val("").trigger("change");

          // HARD RESET composer immediately on chat switch
          $("#message").val("");
          $("#media_image").val("").trigger("change");

          $(".chat_history").empty();  // Clear the previous person's timeline
          $("#conversation-context").empty();
          resetConversationHeader();    // and the previous person's name, avatar and Call identity

          $(this).find(".notification_count").remove();
          $(this).removeClass("conv-unread");

          const chat_id = $(this).data("id");  // Get the clicked chat ID

          $(".chat-users-list li, .chat-users-list-pinned li").removeClass("active");
          $(this).addClass("active");

          $(".chat_id").val(chat_id);
          invalidateComposer();

          loadTimeline(chat_id).done(function() {
            setTimeout(() => {
              $("#message").val("");
              $("#media_image").val("").trigger("change");
            }, 50);
          });
        });

        // The contact panel slides over the timeline on narrower screens.
        $(".conversation-context-toggle").on("click", function() {
          const $panel = $("#conversation-context");
          const open = !$panel.hasClass("show");

          $panel.toggleClass("show", open);
          $(this).attr("aria-expanded", open ? "true" : "false");
        });
      });


      // The file picker is a real button (not a hidden input nested inside a label).
      // Show the exact selected file and let the user remove it before sending MMS.
      $("#media-picker").on("click", function() {
        if (!$(this).prop("disabled")) { $("#media_image").trigger("click"); }
      });
      $("#media_image").on("change", function() {
        const file = this.files && this.files[0];
        const $chip = $("#media-selection");
        if (!file) {
          $("#media-name").text("");
          $chip.prop("hidden", true);
          return;
        }
        const ext = String(file.name || "").split(".").pop().toLowerCase();
        if (!["jpg", "jpeg", "png", "gif", "bmp", "webp", "mp4", "mov", "ogg", "qt"].includes(ext) || file.size > 20000 * 1024) {
          this.value = "";
          $chip.prop("hidden", true);
          toastr["warning"]("Use a JPG, PNG, GIF, WebP or supported video under 20 MB.", "{{ __('locale.labels.attention') }}");
          return;
        }
        $("#media-name").text(file.name);
        $chip.prop("hidden", false);
      });
      $("#media-remove").on("click", function() {
        $("#media_image").val("").trigger("change");
      });

      function safeMessageParagraph(value) {
        return $("<p></p>").text(value);
      }


      // RFC-005 Milestone 5 §6.1 — a chatBoxId-keyed map, never a single
      // flat/session-global token: two different open conversations
      // never share or collide on one token, and a fresh compose always
      // gets a fresh token unless the server explicitly asked this exact
      // conversation to 'retain' its existing one.
      var pendingConversationTokens = pendingConversationTokens || {};

      function m5UuidV4() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
          var r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
          return v.toString(16);
        });
      }

      function enter_chat() {
        // Nothing is sent, on either channel, from a composer that is not configured for the conversation that is open now.
        if (!composerReadyForCurrentConversation()) {
          return;
        }

        if (composerState.channel === "email") {
          sendEmail();
          return;
        }

        let message = $(".message"),
          chatBoxId = $(".chat_id").val(),
          messageValue = message.val(),
          mediaImage = $("#media_image")[0].files[0]; // get the selected file

        // The existing reply endpoint requires text even when an MMS attachment is present.
        if (!String(messageValue || "").trim()) {
          toastr["warning"](mediaImage ? "Write a message to send with your attachment." : "Write a message first.", "{{ __('locale.labels.attention') }}");
          return;
        }
        $(".send").attr("disabled", true);

        if (!pendingConversationTokens[chatBoxId]) {
          pendingConversationTokens[chatBoxId] = m5UuidV4();
        }

        // Use FormData to handle file uploads
        let formData = new FormData();
        formData.append("message", messageValue);
        formData.append("_token", "{{ csrf_token() }}");
        formData.append("idempotency_token", pendingConversationTokens[chatBoxId]);

        if (mediaImage) {
          formData.append("media_image", mediaImage);
        }

        $.ajax({
          url: conversationUrl('reply', chatBoxId),
          type: "POST",
          data: formData,
          processData: false, // prevent jQuery from converting to query string
          contentType: false, // allow multipart/form-data
          success: function(response) {
            $(".send").attr("disabled", false);


            console.log(response);

            // RFC-005 Milestone 5 §6.1 — explicit m5_token_action drives
            // clear/retain, applied uniformly across every response
            // branch below (including the former always-retain error
            // branch). Absent the field entirely (a fully legacy send),
            // default to 'clear' — nothing to retry against.
            if ((response.m5_token_action || 'clear') === 'clear') {
              delete pendingConversationTokens[chatBoxId];
            }

            if (response.status === "processing") {
              // Keep delivery status in the timeline; avoid a popup on each send.
              if (String($(".chat_id").val()) === String(chatBoxId)) { loadTimeline(chatBoxId); }
            } else if (response.status === "success") {

              // Shown straight away, as it always was; the persisted message
              // takes its place the next time this timeline loads.
              let chatHistory = $(".chat_history");
              const $chat = $(`<div class="chat timeline-message" data-role="timeline-message" data-direction="outbound">
                <div class="chat-body">
                  <div class="chat-content"></div>
                </div>
              </div>`);

              const $content = $chat.find(".chat-content");

              $content.append(safeMessageParagraph(messageValue));

              // if media image exists, show it in chat
              if (response.media_url) {
                const $img = $("<img>")
                  .attr("src", response.media_url)
                  .attr("alt", "media")
                  .attr("style", "max-width:200px; max-height:200px;");
                $content.append($("<p></p>").append($img));
              }

              chatHistory.append($chat);
              message.val("");
              $("#media_image").val("").trigger("change"); // reset file input
              $(".user-chats").scrollTop($(".user-chats > .chats").height());
            } else {
              toastr["warning"](response.message, "{{ __('locale.labels.attention') }}", {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });

              // Conversations failed-send/retry — a managed Business's
              // failed attempt is now persisted server-side as a truthful
              // Failed bubble instead of disappearing; reloading the
              // timeline is what shows it, exactly as any other server
              // state does. A full re-render, never an appended duplicate.
              loadTimeline(chatBoxId);
            }
          },
          error: function(reject) {
            $(".send").attr("disabled", false);

            if (reject.status === 422) {
              let errors = reject.responseJSON.errors;
              $.each(errors, function(key, value) {
                toastr["warning"](value[0], "{{__('locale.labels.attention')}}", {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              });
            } else {
              toastr["warning"](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            }
          }
        });
      }

      // Conversations failed-send/retry — delegated because the button is
      // part of the timeline HTML the server re-renders on every load.
      // Disabled immediately on click so a second physical click cannot
      // even reach the network before the first request returns; the
      // server enforces the same guarantee independently (a locked claim
      // on the message row), so this is a UX nicety, not the safety net.
      $(document).on("click", "[data-role='timeline-message-retry']", function() {
        const $btn = $(this);
        const sendUid = $btn.data("send-uid");
        const chatBoxId = $(".chat_id").val();

        if (!sendUid || !chatBoxId || $btn.prop("disabled")) {
          return;
        }

        $btn.prop("disabled", true);

        $.ajax({
          url: conversationUrl('retry', chatBoxId),
          type: "POST",
          data: {
            _token: "{{ csrf_token() }}",
            send_uid: sendUid
          },
          success: function(response) {
            if (response.status !== "success") {
              toastr["warning"](response.message, "{{ __('locale.labels.attention') }}", {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            }

            // The authoritative re-render either way: Sent/Delivered,
            // still Failed, or Delivery failed — never an appended
            // duplicate of the bubble the retry button was already on.
            loadTimeline(chatBoxId);
          },
          error: function() {
            loadTimeline(chatBoxId);
          }
        });
      });


      $(".remove-btn").on("click", function(event) {
        event.preventDefault();
        let sms_id = $(".chat_id").val();

        Swal.fire({
          title: "{{ __('locale.labels.are_you_sure') }}",
          text: "{{ __('locale.labels.able_to_revert') }}",
          icon: "warning",
          showCancelButton: true,
          confirmButtonText: "{{ __('locale.labels.delete_it') }}",
          customClass: {
            confirmButton: "btn btn-primary",
            cancelButton: "btn btn-outline-danger ms-1"
          },
          buttonsStyling: false
        }).then(function(result) {
          if (result.value) {
            $.ajax({
              url: conversationUrl('delete', sms_id),
              type: "POST",
              data: {
                _token: "{{csrf_token()}}"
              },
              success: function(response) {

                if (response.status === "success") {
                  toastr["success"](response.message, '{{__('locale.labels.success')}}!!', {
                    closeButton: true,
                    positionClass: "toast-top-right",
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                  });

                  setTimeout(function() {
                    window.location.reload(); // then reload the page.(3)
                  }, 3000);

                } else {
                  toastr["warning"](response.message, '{{ __('locale.labels.warning') }}!', {
                    closeButton: true,
                    positionClass: "toast-top-right",
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                  });
                }
              },
              error: function(reject) {
                if (reject.status === 422) {
                  let errors = reject.responseJSON.errors;
                  $.each(errors, function(key, value) {
                    toastr["warning"](value[0], "{{__('locale.labels.attention')}}", {
                      closeButton: true,
                      positionClass: "toast-top-right",
                      progressBar: true,
                      newestOnTop: true,
                      rtl: isRtl
                    });
                  });
                } else {
                  toastr["warning"](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                    closeButton: true,
                    positionClass: "toast-top-right",
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                  });
                }
              }
            });
          }
        });

      });

      $(".add-to-blacklist").on("click", function(event) {
        event.preventDefault();
        let sms_id = $(".chat_id").val();

        Swal.fire({
          title: "{{ __('locale.labels.are_you_sure') }}",
          text: "{{ __('locale.labels.remove_blacklist') }}",
          icon: "warning",
          showCancelButton: true,
          confirmButtonText: "{{ __('locale.labels.block') }}",
          customClass: {
            confirmButton: "btn btn-primary",
            cancelButton: "btn btn-outline-danger ms-1"
          },
          buttonsStyling: false
        }).then(function(result) {
          if (result.value) {
            $.ajax({
              url: conversationUrl('block', sms_id),
              type: "POST",
              data: {
                _token: "{{csrf_token()}}"
              },
              success: function(response) {

                if (response.status === "success") {
                  toastr["success"](response.message, '{{__('locale.labels.success')}}!!', {
                    closeButton: true,
                    positionClass: "toast-top-right",
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                  });

                  setTimeout(function() {
                    window.location.reload(); // then reload the page.(3)
                  }, 3000);

                } else {
                  toastr["warning"](response.message, '{{ __('locale.labels.warning') }}!', {
                    closeButton: true,
                    positionClass: "toast-top-right",
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                  });
                }
              },
              error: function(reject) {
                if (reject.status === 422) {
                  let errors = reject.responseJSON.errors;
                  $.each(errors, function(key, value) {
                    toastr["warning"](value[0], "{{__('locale.labels.attention')}}", {
                      closeButton: true,
                      positionClass: "toast-top-right",
                      progressBar: true,
                      newestOnTop: true,
                      rtl: isRtl
                    });
                  });
                } else {
                  toastr["warning"](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                    closeButton: true,
                    positionClass: "toast-top-right",
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                  });
                }
              }
            });
          }
        });

      });

      $(".add-to-pin").on("click", function(event) {
        event.preventDefault();
        const $pin = $(this);
        const uid = String($(".chat_id").val() || "");
        if (!uid || uid.indexOf("email:") === 0 || $pin.prop("disabled")) { return; }
        const wasPinned = $pin.attr("aria-pressed") === "true";
        $pin.prop("disabled", true);
        $.ajax({
          url: conversationUrl('pin', uid),
          type: "POST",
          data: { _token: "{{ csrf_token() }}" },
          dataType: "json"
        }).done(function(response) {
          if (response.status !== "success") {
            toastr["warning"](response.message || "Could not update pin.", "{{ __('locale.labels.attention') }}");
            return;
          }
          if (String($(".chat_id").val()) !== uid) { return; }
          // Direct pin/unpin, no confirmation dialog, success toast or page reload.
          const isPinned = !wasPinned;
          setPinState(isPinned);
          const $row = $("#users-list li[data-id]").filter(function() { return String($(this).attr("data-id")) === uid; }).first();
          if (!$row.length) { return; }
          if (isPinned) {
            let $list = $("#users-list > .chat-users-list-pinned");
            if (!$list.length) {
              $('<h4 class="chat-list-title" data-role="dynamic-pinned-title">Pinned</h4>').prependTo("#users-list");
              $list = $('<ul class="chat-users-list-pinned chat-list media-list" data-role="dynamic-pinned-list"></ul>').insertAfter("#users-list > [data-role=dynamic-pinned-title]");
              $('<h4 class="chat-list-title" data-role="dynamic-chats-title">Chats</h4>').insertBefore("#users-list > .chat-users-list");
            }
            $row.addClass("pinned-row").prependTo($list);
          } else {
            $row.removeClass("pinned-row").prependTo("#users-list > .chat-users-list");
            if (!$("#users-list > .chat-users-list-pinned > li").length) {
              $("#users-list > .chat-users-list-pinned").remove();
              $("#users-list > .chat-list-title").remove();
            }
          }
        }).fail(function(xhr) {
          toastr["warning"]((xhr.responseJSON || {}).message || "Could not update pin.", "{{ __('locale.labels.attention') }}");
        }).always(function() {
          $pin.prop("disabled", false);
        });
      });

      @if(config('broadcasting.connections.pusher.app_id'))
      window.Echo = new Echo({
        broadcaster: "pusher",
        key: "{{ config('broadcasting.connections.pusher.key') }}",
        cluster: "{{ config('broadcasting.connections.pusher.options.cluster') }}",
        encrypted: true,
        authEndpoint: '{{config('app.url')}}/broadcasting/auth'
      });

      Pusher.logToConsole = false;

      // Slice 2B: only this Business's own channel — never the old global
      // "chat" channel every customer shared. routes/channels.php admits a
      // listener only when it could open this Business's inbox.
      Echo.private(@json(\App\Events\MessageReceived::channelFor($businessUid))).listen("MessageReceived", (e) => {
        let chat_id = e.data.uid;
        let box_id = e.data.id;

        // The open conversation reloads its timeline, so the new message
        // arrives in the same server-rendered form as the rest of it. Any
        // other conversation only gets its unread count.
        if ($(".chat_id").val() === String(chat_id)) {
          loadTimeline(chat_id);

          return;
        }

        $.ajax({
          url: conversationUrl('notification', chat_id),
          type: "POST",
          data: {
            _token: "{{csrf_token()}}"
          },
          success: function(response) {
            const $contact = $(`.media-list li[data-box-id=${box_id}]`);
            const $counter = $(".counter", $contact).removeAttr("hidden");
            $(".notification_count", $contact).html(response.notification);
            $counter.html(response.notification);
            $counter.removeAttr("hidden");
          }
        });
      });
      @endif


      $(document).ready(function() {
        let page = 1;
        // A Home card can open this list already narrowed: the inbox's own "waiting for a reply" filter and/or the Business-local
        // days a figure covers. Only those exact shapes are read; anything else leaves the ordinary list.
        const homeScope = new URLSearchParams(window.location.search);
        const isDay = (v) => /^\d{4}-\d{2}-\d{2}$/.test(v || "");
        const createdFrom = isDay(homeScope.get("created_from")) && isDay(homeScope.get("created_to")) ? homeScope.get("created_from") : "";
        const createdTo = createdFrom ? homeScope.get("created_to") : "";
        // The tab the URL names (Home opens "waiting"); anything else is the ordinary default. The pinned rows and the scope banner are
        // rendered by the server from this same URL, so a tab change while "waiting" is active reloads with the new tab instead of
        // leaving waiting-filtered pinned rows and a "waiting" banner beside a different list.
        const knownTabs = ["recents", "unread", "read", "all", "waiting"];
        let filter = knownTabs.indexOf(homeScope.get("filter")) !== -1 ? homeScope.get("filter") : "recents";  // Default filter
        $(".tab-button").removeClass("btn-primary").addClass("btn-outline-primary");
        $(".tab-button").filter(function () { return $(this).data("filter") === filter; }).removeClass("btn-outline-primary").addClass("btn-primary");
        let search = "";     // Default search value
        // Unified inbox channel (All / SMS / Email): an in-page filter, never a navigation. The URL may open it ("?channel=email",
        // which is also where the former standalone Email page now redirects).
        const knownChannels = ["all", "sms", "email"];
        let channel = knownChannels.indexOf(homeScope.get("channel")) !== -1 ? homeScope.get("channel") : "all";
        // Unread / Read / Waiting are SMS notions: email has no read state, so those views list SMS only. Whenever one of them is in effect
        // the Email channel cannot be (it would be an empty list), so the channel becomes SMS — in the URL path and in the UI alike.
        const smsOnlyFilters = ["unread", "read", "waiting"];
        function coerceChannel(ch, f) { return ch === "email" && smsOnlyFilters.indexOf(f) !== -1 ? "sms" : ch; }
        channel = coerceChannel(channel, filter);
        function paintChannel() {
          $(".channel-button").each(function() {
            const on = $(this).data("channel") === channel;
            $(this).toggleClass("btn-primary", on).toggleClass("btn-outline-primary", !on).attr("aria-pressed", on ? "true" : "false");
          });
        }
        paintChannel();

        // Function to load chat users
        function loadChatUsers(page, filter, search, append = false) {
          $.ajax({
            // POST, matching the route's locked verb: the list is only ever
            // read by this page's own AJAX, so it has no GET alias.
            url: conversationRoutes.load,
            type: "POST",
            data: {
              _token: "{{ csrf_token() }}",
              page: page,
              filter: filter,
              search: search,
              created_from: createdFrom,
              created_to: createdTo,
              channel: channel
            },
            beforeSend: function() {
              $("#loader").show();  // Show the loader before the request
            },
            success: function(response) {
              $("#loader").hide();  // Hide the loader after data is loaded
              if (append) {
                // A person can be reachable through two windows of the merged list: never show the same row twice.
                const $incoming = $("<ul></ul>").html(response);
                $incoming.children("li").each(function() {
                  const id = String($(this).attr("data-id"));
                  if ($(".chat-users-list > li").filter(function() { return String($(this).attr("data-id")) === id; }).length) {
                    $(this).remove();
                  }
                });
                $(".chat-users-list").append($incoming.children()); // Append new data
              } else {
                $(".chat-users-list").html(response);   // Replace data
              }

              feather.replace();

            },
            error: function() {
              $("#loader").hide();  // Hide loader in case of error
              toastr["warning"]('{{ __('locale.exceptions.something_went_wrong') }}', "{{ __('locale.labels.attention') }}", {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
              });
            }
          });
        }

        // Initial load
        loadChatUsers(page, filter, search);

        // Deep link from Outreach (?open=<conversation uid>): open that conversation
        // once the list has loaded. Best effort; nothing happens if it is not listed.
        (function () {
          const wanted = new URLSearchParams(window.location.search).get("open");
          if (!wanted) { return; }
          let tries = 0;
          const timer = setInterval(function () {
            const $row = $("#users-list li").filter(function () { return String($(this).attr("data-id")) === wanted; }).first();
            if ($row.length) { clearInterval(timer); $row.trigger("click"); }
            else if (++tries > 20) { clearInterval(timer); }
          }, 250);
        })();

        // Deep link to one email thread inside Conversations (?channel=email&thread=<key>): saved links from the former Email page.
        (function () {
          const thread = homeScope.get("thread");
          if (!thread) { return; }
          $(".chat_id").val("email:" + thread);
          invalidateComposer();
          loadTimeline("email:" + thread);
        })();

// Add debounce function to delay the search request
        function debounce(func, delay) {
          let timeout;
          return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), delay);
          };
        }

// Channel filter: All / SMS / Email, in place.
        $(".channel-button").on("click", function() {
          channel = $(this).data("channel");
          paintChannel();
          // Unread / Read / Waiting are SMS notions; an Email-only view always lists everything.
          if (channel === "email" && filter !== "recents" && filter !== "all") {
            filter = "recents";
            $(".tab-button").removeClass("btn-primary").addClass("btn-outline-primary");
            $(".tab-button").filter(function () { return $(this).data("filter") === filter; }).removeClass("btn-outline-primary").addClass("btn-primary");
          }
          page = 1;
          loadChatUsers(page, filter, search);
        });

// Filter data by tab
        $(".tab-button").on("click", function() {
          // Change the color of the clicked button to primary and reset others
          $(".tab-button").removeClass("btn-primary").addClass("btn-outline-primary");
          $(this).removeClass("btn-outline-primary").addClass("btn-primary");

          // Leaving the "waiting" view: the server-rendered pinned rows and banner must follow, so reload on the chosen tab
          // (an explicit created-date range in the URL is kept).
          const chosen = $(this).data("filter");
          channel = coerceChannel(channel, chosen);
          paintChannel();

          if (filter === "waiting") {
            const next = new URL(window.location.href);
            next.searchParams.set("filter", chosen);
            // Keep the channel consistent with the new tab (Home date-scope parameters are untouched).
            if (channel === "all") { next.searchParams.delete("channel"); } else { next.searchParams.set("channel", channel); }
            window.location.assign(next.toString());
            return;
          }

          // Get filter value and reset page
          page = 1;
          filter = chosen;
          loadChatUsers(page, filter, search);
        });

// Load more data when "Load More" button is clicked
        $("#load-more").on("click", function() {
          page += 1;  // Increment page number
          loadChatUsers(page, filter, search, true);  // Append new data
        });

// Search functionality with debounce
        $("#chat-search").on("keyup", debounce(function() {
          search = $(this).val();  // Get search value
          page = 1;  // Reset page to 1
          loadChatUsers(page, filter, search);
        }, 500));  // Delay of 500ms

      });


      $("#users-list").on("scroll", function(e) {
        e.preventDefault();
        let div = $(this).get(0);
        if (div.scrollTop + div.clientHeight >= div.scrollHeight) {
          // do the lazy loading here
          $("#load-more").trigger("click");
        }
      });


    </script>
@endsection
