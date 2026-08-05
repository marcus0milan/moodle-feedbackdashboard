<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Library callbacks for the Feedback Dashboard plugin.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds the Dashboard tab directly to the Feedback page HTML.
 *
 * @return string
 */
function local_feedbackdashboard_before_standard_footer_html(): string {
    global $PAGE;

    if (empty($PAGE->cm) || $PAGE->cm->modname !== 'feedback') {
        return '';
    }

    $context = context_module::instance($PAGE->cm->id);

    if (
        !is_siteadmin() &&
        !has_capability('local/feedbackdashboard:view', $context)
    ) {
        return '';
    }

    $dashboardurl = new moodle_url(
        '/local/feedbackdashboard/index.php',
        ['id' => $PAGE->cm->id]
    );

    $dashboardurljson = json_encode(
        $dashboardurl->out(false),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );

    $dashboardlabeljson = json_encode(
        get_string('dashboard', 'local_feedbackdashboard'),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );

    $javascript = <<<JS
(function() {
    'use strict';

    const dashboardUrl = {$dashboardurljson};
    const dashboardLabel = {$dashboardlabeljson};

    const findNavigationList = () => {
        const selectors = [
            '.secondary-navigation .moremenu .nav-tabs',
            '.secondary-navigation .nav-tabs',
            '[data-region="secondary-navigation"] .nav-tabs',
            'nav.moremenu .nav-tabs',
            '.moremenu.navigation .nav-tabs',
            '.secondary-navigation ul.nav'
        ];

        for (const selector of selectors) {
            const element = document.querySelector(selector);

            if (element) {
                return element;
            }
        }

        return null;
    };

    const addDashboardTab = () => {
        const navigationList = findNavigationList();

        if (!navigationList) {
            return false;
        }

        if (
            navigationList.querySelector(
                '[data-key="local-feedbackdashboard"]'
            )
        ) {
            return true;
        }

        const listItem = document.createElement('li');
        listItem.className = 'nav-item';
        listItem.dataset.key = 'local-feedbackdashboard';
        listItem.setAttribute('role', 'none');

        const link = document.createElement('a');
        link.className = 'nav-link';
        link.href = dashboardUrl;
        link.textContent = dashboardLabel;
        link.dataset.key = 'local-feedbackdashboard';
        link.setAttribute('role', 'menuitem');

        if (
            window.location.pathname.includes(
                '/local/feedbackdashboard/index.php'
            )
        ) {
            link.classList.add('active');
            link.setAttribute('aria-current', 'page');
        }

        listItem.appendChild(link);

        const items = Array.from(navigationList.children);

        const moreItem = items.find((item) => {
            if (!(item instanceof HTMLElement)) {
                return false;
            }

            return (
                item.classList.contains('dropdown') ||
                item.classList.contains('dropdownmoremenu') ||
                item.querySelector(
                    '.dropdown-toggle, ' +
                    '[data-toggle="dropdown"], ' +
                    '[data-bs-toggle="dropdown"]'
                ) !== null
            );
        });

        if (moreItem) {
            navigationList.insertBefore(listItem, moreItem);
        } else {
            navigationList.appendChild(listItem);
        }

        return true;
    };

    const initialise = () => {
        if (addDashboardTab()) {
            return;
        }

        const observer = new MutationObserver(() => {
            if (addDashboardTab()) {
                observer.disconnect();
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        window.setTimeout(() => {
            observer.disconnect();
        }, 10000);
    };

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initialise,
            {once: true}
        );
    } else {
        initialise();
    }
})();
JS;

    $PAGE->requires->js_init_code($javascript);

    return '';
}