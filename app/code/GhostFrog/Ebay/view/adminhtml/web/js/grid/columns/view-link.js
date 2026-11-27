/**
 * Link column that shows a short "View" label instead of the full URL.
 */
define(['Magento_Ui/js/grid/columns/link', 'mage/translate'], function (Link, $t) {
    'use strict';

    return Link.extend({
        getLabel: function (record) {
            return this.isLink(record) ? $t('View') : '';
        }
    });
});
