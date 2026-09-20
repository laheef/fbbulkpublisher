'use strict';

/**
 * Publish verification (prompt §22, §67).
 *
 * A job is only reported as published when we have evidence:
 *   1. a direct link to the new post, or
 *   2. the story is visible on the Page timeline with matching content.
 *
 * If neither is provable we raise PUBLISH_VERIFICATION_REQUIRED — the job is
 * paused for a human, never marked successful and never re-published blindly.
 * This is what stops a crash mid-publish from turning into a duplicate post.
 */

const selectors = require('../facebook/selectors');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'browser' });

function canonicalPostUrl(href) {
    if (!href) {
        return null;
    }
    try {
        const url = new URL(href, 'https://www.facebook.com');
        if (!/(\/posts\/|\/videos\/|story_fbid=|permalink|\/reel\/)/.test(url.pathname + url.search)) {
            return null;
        }
        url.hash = '';
        ['__tn__', '__cft__', 'ref', 'mibextid'].forEach((key) => url.searchParams.delete(key));
        return url.toString();
    } catch {
        return null;
    }
}

/** Read the newest timeline story and decide whether it matches the job. */
async function verifyPublication(page, job, { waitMs = 45000 } = {}) {
    const captionFragment = normalise(job.content?.caption || '').slice(0, 60);
    const linkFragment = normalise(job.content?.link_url || '');
    const deadline = Date.now() + waitMs;

    let lastSeen = null;

    while (Date.now() < deadline) {
        const stories = await readTopStories(page, 3);

        for (const story of stories) {
            lastSeen = story;
            const matchesCaption = captionFragment.length > 8
                && normalise(story.text).includes(captionFragment);
            const matchesLink = linkFragment.length > 8
                && normalise(story.text).includes(linkFragment);
            const matchesMedia = Boolean(job.media) && Boolean(story.hasMedia);

            if (matchesCaption || matchesLink || (matchesMedia && matchesCaption)) {
                return {
                    verified: true,
                    url: story.url || null,
                    evidence: matchesCaption ? 'caption_match' : (matchesLink ? 'link_match' : 'media_match'),
                };
            }

            // Media-only posts have no text to compare; require the media flag.
            if (matchesMedia && !captionFragment) {
                return { verified: true, url: story.url || null, evidence: 'media_only_match' };
            }
        }

        await page.waitForTimeout(2000);
    }

    log.warn('Publication could not be verified automatically.', {
        job: job.id,
        sawStory: Boolean(lastSeen),
    });

    return {
        verified: false,
        code: 'PUBLISH_VERIFICATION_REQUIRED',
        detail: lastSeen
            ? 'A post appeared on the Page but it could not be confirmed as this job\'s post. Open the Page and check before retrying — this prevents duplicates.'
            : 'No post appeared on the Page timeline in time. Open the Page and check before retrying.',
        candidateUrl: lastSeen?.url || null,
    };
}

async function readTopStories(page, limit = 3) {
    try {
        return await page.evaluate((count) => {
            const articles = Array.from(document.querySelectorAll('div[role="article"]')).slice(0, count);
            return articles.map((article) => {
                const link = article.querySelector('a[href*="/posts/"], a[href*="story_fbid"], a[href*="/videos/"], a[href*="/reel/"]');
                const href = link ? link.getAttribute('href') : null;
                return {
                    text: (article.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 400),
                    hasMedia: Boolean(article.querySelector('img[src*="scontent"], video')),
                    url: href ? new URL(href, 'https://www.facebook.com').toString() : null,
                };
            });
        }, limit);
    } catch {
        return [];
    }
}

/**
 * Re-read a known post URL to confirm it still exists (used after an uncertain
 * crash, so we never publish the same thing twice).
 */
async function confirmExistingPost(page, url) {
    try {
        const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
        if (!response || response.status() >= 400) {
            return { exists: false, status: response ? response.status() : null };
        }
        const gone = await selectors.resolve(page, 'challenge.securityBlock', { timeoutMs: 1000, visible: true });
        if (gone) {
            return { exists: false, status: response.status(), restricted: true };
        }
        const article = await selectors.resolve(page, 'post.storyContainer', { timeoutMs: 8000, visible: false });
        return { exists: Boolean(article), status: response.status(), url: page.url() };
    } catch (error) {
        return { exists: false, error: String(error) };
    }
}

function normalise(text) {
    return String(text || '')
        .replace(/\s+/g, ' ')
        .replace(/[\u200b-\u200f\uFEFF]/g, '')
        .trim()
        .toLowerCase();
}

module.exports = { verifyPublication, confirmExistingPost, canonicalPostUrl, normalise };
