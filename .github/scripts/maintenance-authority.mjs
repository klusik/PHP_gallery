/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/maintenance-authority.mjs
 * Module Type: Reviewed Maintenance Ancestry Reader
 * Purpose: Resolve protected main merge history to existing stable tags without replaying historical test qualification.
 * Responsibilities: Preserve bounded first-parent Git ancestry and complete paginated provider inventories.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */




/** Bound maintenance ancestry and provider inventories independently of release version.
 * Type: number. Units: merges or pages. Scope: one maintenance lineage verification.
 * Consumers: verifyMaintenanceLineage and maintenanceRows.
 * Rationale: thirty-two reviewed merges/pages prevent unbounded provider traversal while ordinary releases need none.
 */
export const MAINTENANCE_READ_LIMIT=32;

/** Read a complete stable provider inventory with distinct positive row identities.
 * @param {function(string):Record<string,unknown>} call Read-only GitHub API adapter.
 * @param {string} path Collection endpoint without pagination parameters.
 * @param {string} key Provider array key.
 * @returns {Record<string,unknown>[]} Complete rows; missing, duplicated or changing collections throw.
 */
export function maintenanceRows(call,path,key) {
    const rows=[],ids=new Set();let total=null;
    for (let page=1;page<=MAINTENANCE_READ_LIMIT;page++) {
        const data=call(path+(path.includes('?')?'&':'?')+'per_page=100&page='+page);
        if (!Array.isArray(data?.[key]) || !Number.isSafeInteger(data.total_count) || data.total_count<0
            || total!==null && total!==data.total_count) throw new Error('BLOCKED incomplete maintenance '+key+' inventory.');
        total=data.total_count;
        for (const row of data[key]) {
            if (!Number.isSafeInteger(row?.id) || row.id<1 || ids.has(row.id)) throw new Error('BLOCKED duplicate maintenance provider row.');
            ids.add(row.id);rows.push(row);
        }
        if (rows.length===total) return rows;
        if (!data[key].length || rows.length>total) break;
    }
    throw new Error('BLOCKED truncated maintenance '+key+' inventory.');
}

/** Read the first parent of an already protected main merge without replaying its CI or review history.
 * @param {string} repository Exact owner/repository.
 * @param {string} mergeSha Immutable selected main merge.
 * @param {import('./coverage-artifacts.mjs').ArtifactAdapters} adapters Read-only provider adapter.
 * @returns {string} Actual first parent; malformed Git ancestry throws.
 */
export function verifyMaintenanceMerge(repository,mergeSha,adapters) {
    const merge=adapters.api(`repos/${repository}/commits/${mergeSha}`);
    if (merge?.sha!==mergeSha || merge.parents?.length!==2
        || !merge.parents.every(parent=>/^[a-f0-9]{40}$/.test(parent.sha ?? ''))) {
        throw new Error('BLOCKED main predecessor is not a standard protected merge.');
    }
    return merge.parents[0].sha;
}

/** Resolve the selected stable commit through protected main's first-parent merge history.
 * @param {string} repository Exact owner/repository.
 * @param {string} main Immutable selected main commit.
 * @param {string} stable Independently resolved stable tag commit.
 * @param {import('./coverage-artifacts.mjs').ArtifactAdapters} adapters Read-only provider adapter.
 * @returns {void} Returns for a complete bounded Git lineage; never reruns historical qualification.
 */
export function verifyMaintenanceLineage(repository,main,stable,adapters) {
    if (![main,stable].every(value=>/^[a-f0-9]{40}$/.test(value ?? '')) || typeof adapters.api!=='function') {
        throw new Error('BLOCKED malformed main predecessor lineage.');
    }
    const seen=new Set();let current=main;
    for (let depth=0;current!==stable && depth<MAINTENANCE_READ_LIMIT;depth++) {
        if (seen.has(current)) throw new Error('BLOCKED cyclic main predecessor lineage.');
        seen.add(current);current=verifyMaintenanceMerge(repository,current,adapters);
    }
    if (current!==stable) throw new Error('BLOCKED main predecessor lineage exceeds its bound.');
}
