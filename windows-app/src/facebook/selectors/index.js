'use strict';

/**
 * Centralised Facebook selectors (prompt §59).
 *
 * Every Facebook-specific DOM detail lives in this folder. When Facebook
 * changes its interface the fix belongs here — never in a job runner, and never
 * as a "click things until something happens" fallback. If a required selector
 * cannot be resolved we report FACEBOOK_UI_CHANGED and stop.
 *
 * Selector order is deliberate: accessible role/text first (most stable),
 * then structural hooks, then attribute hooks. Each entry declares
 * `required: true` when its absence must abort instead of degrade.
 */

const SELECTORS = {
    version: '2026.09.19',

    login: {
        emailInput: {
            required: true,
            candidates: [
                'input[name="email"]',
                'input#email',
                'input[type="text"][autocomplete="username"]',
            ],
        },
        passwordInput: {
            required: true,
            candidates: [
                'input[name="pass"]',
                'input#pass',
                'input[type="password"]',
            ],
        },
        submitButton: {
            required: true,
            candidates: [
                'button[name="login"]',
                'button[type="submit"]',
                'div[aria-label="Log in"]',
            ],
        },
        loggedInMarker: {
            required: false,
            candidates: [
                '[aria-label="Your profile"]',
                '[data-pagelet="LeftRail"]',
                'div[role="navigation"]',
            ],
        },
        twoFactorField: {
            required: false,
            candidates: [
                'input[name="approvals_code"]',
                'input[autocomplete="one-time-code"]',
                'input[aria-label*="code"]',
            ],
        },
    },

    composer: {
        /** The "What's on your mind?" trigger on the Page timeline. */
        composerTrigger: {
            required: true,
            candidates: [
                '[aria-label="Create a post"]',
                'div[role="button"]:has-text("What\'s on your mind")',
                'div[role="button"]:has-text("Write something")',
                '[data-pagelet="ProfileComposer"] [role="button"]',
            ],
        },
        modal: {
            required: true,
            candidates: [
                'div[role="dialog"]',
                '[aria-label="Create post"]',
            ],
        },
        textArea: {
            required: true,
            candidates: [
                'div[role="dialog"] div[contenteditable="true"][role="textbox"]',
                'div[role="dialog"] [contenteditable="true"]',
            ],
        },
        postButton: {
            required: true,
            candidates: [
                'div[role="dialog"] div[aria-label="Post"][role="button"]',
                'div[role="dialog"] [aria-label="Post"]',
                'div[role="dialog"] div[role="button"]:has-text("Post")',
            ],
        },
        photoVideoButton: {
            required: false,
            candidates: [
                'div[role="dialog"] [aria-label="Photo/video"]',
                'div[role="dialog"] [aria-label*="Photo"]',
                'div[role="dialog"] div[role="button"]:has-text("Photo/video")',
            ],
        },
        fileInput: {
            required: true,
            candidates: [
                'div[role="dialog"] input[type="file"]',
                'input[type="file"][accept*="image"]',
                'input[type="file"][accept*="video"]',
                'input[type="file"]',
            ],
        },
        /** While a media file uploads; used for robust waiting, not for clicking. */
        uploadProgress: {
            required: false,
            candidates: [
                'div[role="dialog"] [role="progressbar"]',
                'div[role="dialog"] [aria-label*="Uploading"]',
            ],
        },
        mediaReadyMarker: {
            required: false,
            candidates: [
                'div[role="dialog"] img[src*="scontent"]',
                'div[role="dialog"] video',
                'div[role="dialog"] [aria-label*="Remove"]',
            ],
        },
        scheduleButton: {
            required: false,
            candidates: [
                'div[role="dialog"] [aria-label*="Scheduling"]',
                'div[role="dialog"] [aria-label*="Schedule"]',
            ],
        },
        scheduleOption: {
            required: false,
            candidates: [
                'div[role="radio"][aria-label*="Schedule"]',
                'div[role="menuitem"]:has-text("Schedule")',
            ],
        },
        scheduleDateTime: {
            required: false,
            candidates: [
                'input[type="datetime-local"]',
                'input[aria-label*="Date"]',
            ],
        },
        scheduleConfirm: {
            required: false,
            candidates: [
                'div[role="dialog"] [aria-label="Schedule"]',
                'div[role="dialog"] button:has-text("Schedule")',
            ],
        },
        closeDialog: {
            required: false,
            candidates: [
                'div[role="dialog"] [aria-label="Close"]',
                'div[role="dialog"] [aria-label="Cancel"]',
            ],
        },
    },

    post: {
        /** Confirmation that a timeline post now exists. */
        storyContainer: {
            required: false,
            candidates: [
                'div[role="article"]',
                'div[data-pagelet^="TimelineFeedUnit"]',
            ],
        },
        resultLink: {
            required: false,
            candidates: [
                'div[role="article"] a[href*="/posts/"]',
                'div[role="article"] a[href*="story_fbid"]',
                'a[href*="/posts/"]',
            ],
        },
        menuButton: {
            required: false,
            candidates: [
                'div[role="article"] [aria-label="Actions for this post"]',
                'div[role="article"] [aria-haspopup="menu"]',
            ],
        },
        publishedToast: {
            required: false,
            candidates: [
                '[role="alert"]:has-text("published")',
                'div:has-text("Your post is now published")',
            ],
        },
    },

    pages: {
        switcher: {
            required: false,
            candidates: [
                '[aria-label="Your profile"] [role="button"]',
                'div[aria-label*="Switch"]',
            ],
        },
        accountList: {
            required: false,
            candidates: [
                'div[role="dialog"] div[role="button"]',
                'ul[role="list"] li',
            ],
        },
        pageRowLink: {
            required: false,
            candidates: [
                'a[href*="/profile.php?id="]',
                'a[href^="/"][role="link"]',
            ],
        },
        /** Page inbox / meta bar visible once a Page profile is loaded. */
        pageLoadedMarker: {
            required: false,
            candidates: [
                '[aria-label="Page transparency"]',
                'div[role="main"] [data-pagelet="ProfileTilesFeed"]',
            ],
        },
        pageManagementLink: {
            required: false,
            candidates: [
                'a[href*="/settings/?tab=page"]',
                'a:has-text("Manage")',
            ],
        },
    },

    challenge: {
        captcha: {
            required: false,
            candidates: [
                'iframe[src*="captcha"]',
                'iframe[title*="captcha" i]',
                'div[class*="captcha" i]',
                'img[src*="captcha" i]',
                '[aria-label*="captcha" i]',
            ],
        },
        checkpoint: {
            required: false,
            candidates: [
                'div[role="dialog"]:has-text("confirm your identity")',
                'form[action*="checkpoint"]',
                '[data-testid*="checkpoint"]',
                'a[href*="/checkpoint/"]',
            ],
        },
        twoFactor: {
            required: false,
            candidates: [
                'input[name="approvals_code"]',
                'input[autocomplete="one-time-code"]',
                'div:has-text("Two-factor authentication")',
                'div:has-text("Enter the code")',
            ],
        },
        identity: {
            required: false,
            candidates: [
                'div:has-text("We need to verify your identity")',
                'div[role="dialog"]:has-text("Upload your ID")',
                'div:has-text("Confirm your identity")',
            ],
        },
        securityBlock: {
            required: false,
            candidates: [
                'div[role="dialog"]:has-text("You\'re temporarily blocked")',
                'div:has-text("We detected unusual activity")',
                'div:has-text("Your account has been disabled")',
                'div:has-text("restricted")',
            ],
        },
        rateLimit: {
            required: false,
            candidates: [
                'div:has-text("You\'re posting too fast")',
                'div:has-text("try again later")',
                'div:has-text("temporarily blocked")',
            ],
        },
    },

    session: {
        profileMenu: {
            required: false,
            candidates: [
                '[aria-label="Your profile"]',
                '[aria-label="Account"]',
                '[aria-label="Account controls and settings"]',
            ],
        },
        passwordPrompt: {
            required: false,
            candidates: [
                'input[name="pass"]',
                'input[type="password"]',
                'div:has-text("Please enter your password")',
            ],
        },
        loggedOutMarker: {
            required: false,
            candidates: [
                'form[action*="login"]',
                'button[name="login"]',
                'a[href*="/login/"]',
            ],
        },
    },
};

/** Flatten "section.key" lookups, e.g. resolve('composer.textArea'). */
function entry(path) {
    const segments = String(path).split('.');
    let cursor = SELECTORS;
    for (const segment of segments) {
        if (!cursor || typeof cursor !== 'object' || !(segment in cursor)) {
            return null;
        }
        cursor = cursor[segment];
    }
    return cursor && cursor.candidates ? cursor : null;
}

/** CSS selectors joined for a single `locator(...)` call. */
function css(path) {
    const found = entry(path);
    return found ? found.candidates.join(', ') : null;
}

function isRequired(path) {
    const found = entry(path);
    return Boolean(found && found.required);
}

/**
 * Resolve the first candidate that matches, waiting briefly for late render.
 * Returns null when nothing matches — callers must treat that as a UI change
 * for required selectors, never as "click somewhere else".
 */
async function resolve(page, path, { timeoutMs = 15000, visible = true } = {}) {
    const found = entry(path);
    if (!found) {
        return null;
    }

    const deadline = Date.now() + timeoutMs;

    while (Date.now() < deadline) {
        for (const selector of found.candidates) {
            const locator = page.locator(selector).first();
            try {
                if (visible) {
                    if (await locator.isVisible({ timeout: 500 })) {
                        return locator;
                    }
                } else if (await locator.count() > 0) {
                    return locator;
                }
            } catch {
                /* candidate not present; try the next one */
            }
        }
        await page.waitForTimeout(250);
    }

    return null;
}

module.exports = { SELECTORS, entry, css, isRequired, resolve };
