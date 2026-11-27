/**
 * Grid listing that tints each row with the display colour of the row's source
 * and keeps the actions column last, whatever order a saved view stored.
 */
define(['Magento_Ui/js/grid/listing'], function (Listing) {
    'use strict';

    var HEX = /^#[0-9a-f]{6}$/i,
        ACTIONS = 'actions';

    /** Returns the row's source colour, or null when the source has none. */
    function colourOf(row) {
        var colour = row && row.source_colour;

        return typeof colour === 'string' && HEX.test(colour) ? colour : null;
    }

    /** Returns a copy of the positions with the actions column after every other column. */
    function actionsLast(positions) {
        var result = {},
            last = -1;

        if (!positions || typeof positions !== 'object' || !positions.hasOwnProperty(ACTIONS)) {
            return positions;
        }

        Object.keys(positions).forEach(function (name) {
            if (name !== ACTIONS) {
                result[name] = positions[name];
                last = Math.max(last, positions[name]);
            }
        });
        result[ACTIONS] = last + 1;

        return result;
    }

    return Listing.extend({
        defaults: {
            template: 'GhostFrog_Ebay/grid/source-colour-listing'
        },

        sourceColour: colourOf,

        sourceColourStyle: function (row) {
            var colour = colourOf(row);

            return colour ? '--gf-source-colour: ' + colour : null;
        },

        updatePositions: function () {
            var positions = {};

            this.elems.each(function (elem, index) {
                positions[elem.index] = index;
            });

            this.set('positions', actionsLast(positions));

            return this;
        },

        applyPositions: function (positions) {
            return this._super(actionsLast(positions));
        }
    });
});
