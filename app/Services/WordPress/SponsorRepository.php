<?php

namespace App\Services\WordPress;

use Illuminate\Http\UploadedFile;

/**
 * Reading and writing the partners shown on a film site.
 *
 * WordPress is the single source of truth: the Win App stores none of this.
 *
 * The shape follows the film sites rather than the other way round. A partners
 * page is built from **groups**, not from individual partners — one group per
 * tier per region per season, e.g. "WAFT AU – SUPPORTING PARTNERS 2026". Each
 * group carries a heading and a repeater of partner rows, and a row is just a
 * logo, a link and how tall to draw it. There is no such thing as a standalone
 * partner record to create, which is why this interface has no create($sponsor)
 * and why a partner is addressed as (group id, row index).
 *
 * A group comes back as:
 *   id, title, heading, status, regions[], seasons[], link, partners[]
 *
 * A partner row comes back as:
 *   index, logo_id, logo_url, website_url, text, max_height, line_break
 */
interface SponsorRepository
{
    /** Which backing this instance is — shown in the dashboard so nobody mistakes mock data for real. */
    public function driver(): string;

    /** True when this repository is talking to a real WordPress site. */
    public function isLive(): bool;

    /**
     * Fail now if a write could not possibly land.
     *
     * Called before a logo is uploaded. The media endpoint is core and always
     * open, so an upload succeeds even when the partner post type is still
     * unreachable — which would leave an unreferenced image in the library on
     * every failed save. Checking first keeps a broken site from collecting
     * litter.
     */
    public function assertWritable(): void;

    /**
     * @param  array{region?: ?string, season?: ?string, search?: ?string}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function groups(array $filters = []): array;

    /** @return array<string, mixed>|null */
    public function group(int $id): ?array;

    /**
     * Start a new tier block — a heading plus the region and season it belongs to.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createGroup(array $data): array;

    /**
     * Rename a group or re-tag which region and season it belongs to. Does not
     * touch its partner rows.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateGroup(int $id, array $data): array;

    public function deleteGroup(int $id): void;

    /**
     * Append a partner to a group. This is what "upload a partner logo" means.
     *
     * @param  array<string, mixed>  $partner
     * @return array<string, mixed> the group as it now stands
     */
    public function addPartner(int $groupId, array $partner): array;

    /**
     * @param  array<string, mixed>  $partner
     * @return array<string, mixed> the group as it now stands
     */
    public function updatePartner(int $groupId, int $index, array $partner): array;

    public function removePartner(int $groupId, int $index): void;

    /**
     * Set the order of a group's partners. The repeater's order is the order
     * the logos appear in, so this is what "position" means on the page.
     *
     * @param  array<int, int>  $indices  every current index, in the new order
     * @return array<string, mixed> the group as it now stands
     */
    public function reorderPartners(int $groupId, array $indices): array;

    /**
     * Set the order of the groups on one page, which is the order the tier
     * blocks appear in down that page.
     *
     * @param  array<int, int>  $groupIds  every group currently on the page, in the new order
     */
    public function reorderPage(int $pageId, array $groupIds): void;

    /**
     * The partners pages on the film site, and which groups each one shows.
     *
     * A group being published only means it *may* be shown. What actually puts
     * it on a page is the page's own partners block, which names the groups it
     * displays. Most groups on a site are on no page at all.
     *
     * @return array<int, array{id: int, title: string, link: ?string, group_ids: array<int, int>}>
     */
    public function pages(): array;

    /** Add a group to a page's partners block, so the page starts showing it. */
    public function placeGroup(int $pageId, int $groupId): void;

    /** Take a group off a page. The group and its partners are left alone. */
    public function unplaceGroup(int $pageId, int $groupId): void;

    /**
     * Put a logo in the WordPress media library, ready to be referenced by a row.
     *
     * @return array{id: int, url: string}
     */
    public function uploadLogo(UploadedFile $file): array;

    /**
     * Region and season terms, as the site defines them — never hardcoded here,
     * because slugs differ per film site (WAFT uses 'new-zealand', RunNation 'nz').
     *
     * @return array{region: array<int, array{id: int, name: string, slug: string}>, season: array<int, array{id: int, name: string, slug: string}>}
     */
    public function taxonomies(): array;
}
