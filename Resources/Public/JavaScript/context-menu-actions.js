/**
 * Context menu callback for "Landing Page erstellen" action.
 *
 * Called by TYPO3 when the user clicks the context menu item
 * registered in LandingPageItemProvider.
 */
class ContextMenuActions {
    /**
     * @param {string} table
     * @param {number} uid
     * @param {Object} dataset
     */
    createLandingPage(table, uid, dataset) {
        if (dataset.navigateUri) {
            top.TYPO3.Backend.ContentContainer.setUrl(dataset.navigateUri);
        }
    }
}

export default new ContextMenuActions();
