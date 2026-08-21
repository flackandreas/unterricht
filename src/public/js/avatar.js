/**
 * src/public/js/avatar.js
 * Erzeugt Avatare direkt im Browser.
 *
 * Ersetzt api.dicebear.com. Dort wurde der Seed als URL-Parameter uebergeben -
 * und der Seed war der Klarname der Schuelerin oder des Schuelers. Damit ging
 * bei jedem Seitenaufruf ein personenbezogenes Datum an einen Dritten. Die
 * Erzeugung passiert jetzt lokal, es verlaesst nichts mehr das Geraet.
 */
(function (global) {
    'use strict';

    var STYLES = ['adventurer', 'bottts', 'fun-emoji', 'lorelei', 'pixel-art'];

    var PALETTES = [
        ['#2ecc71', '#0f766e'], ['#3b82f6', '#1e40af'], ['#8b5cf6', '#5b21b6'],
        ['#f97316', '#9a3412'], ['#ec4899', '#9d174d'], ['#14b8a6', '#115e59'],
        ['#eab308', '#854d0e'], ['#ef4444', '#991b1b']
    ];

    var SKINS = ['#f2d3b1', '#e0ac69', '#c68642', '#8d5524', '#ffdbac', '#a1665e'];
    var HAIR = ['#2c1b18', '#4a312c', '#a55728', '#b58143', '#d6b370', '#71635a'];

    /** Deterministischer 32-Bit-Hash (FNV-1a). */
    function hash(seed) {
        var h = 2166136261;
        var text = String(seed || 'anon');
        for (var i = 0; i < text.length; i++) {
            h ^= text.charCodeAt(i);
            h = (h * 16777619) >>> 0;
        }
        return h >>> 0;
    }

    /** Liefert aus dem Hash eine Folge reproduzierbarer Zahlen. */
    function picker(seed) {
        var state = hash(seed) || 1;
        return function (max) {
            // xorshift32
            state ^= state << 13; state >>>= 0;
            state ^= state >> 17;
            state ^= state << 5;  state >>>= 0;
            return state % max;
        };
    }

    function svg(inner) {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">' + inner + '</svg>';
    }

    function eyes(cx1, cx2, cy, r, color) {
        return '<circle cx="' + cx1 + '" cy="' + cy + '" r="' + r + '" fill="' + color + '"/>'
             + '<circle cx="' + cx2 + '" cy="' + cy + '" r="' + r + '" fill="' + color + '"/>';
    }

    function mouth(variant, color) {
        switch (variant) {
            case 0: return '<path d="M38 66 Q50 76 62 66" stroke="' + color + '" stroke-width="3" fill="none" stroke-linecap="round"/>';
            case 1: return '<path d="M38 68 L62 68" stroke="' + color + '" stroke-width="3" fill="none" stroke-linecap="round"/>';
            case 2: return '<ellipse cx="50" cy="68" rx="7" ry="5" fill="' + color + '"/>';
            default: return '<path d="M38 70 Q50 60 62 70" stroke="' + color + '" stroke-width="3" fill="none" stroke-linecap="round"/>';
        }
    }

    var RENDERERS = {
        'pixel-art': function (pick, palette) {
            var out = '<rect width="100" height="100" fill="' + palette[1] + '" opacity="0.15"/>';
            // 5x5 Raster, links/rechts gespiegelt
            for (var y = 0; y < 5; y++) {
                for (var x = 0; x < 3; x++) {
                    if (pick(100) < 55) {
                        var color = pick(4) === 0 ? palette[1] : palette[0];
                        out += '<rect x="' + (10 + x * 20) + '" y="' + (10 + y * 16) + '" width="20" height="16" fill="' + color + '"/>';
                        if (x < 2) {
                            out += '<rect x="' + (10 + (4 - x) * 20) + '" y="' + (10 + y * 16) + '" width="20" height="16" fill="' + color + '"/>';
                        }
                    }
                }
            }
            return out;
        },

        'bottts': function (pick, palette) {
            var eyeColor = '#0f172a';
            return '<rect width="100" height="100" rx="16" fill="' + palette[1] + '" opacity="0.15"/>'
                 + '<line x1="50" y1="6" x2="50" y2="20" stroke="' + palette[1] + '" stroke-width="3"/>'
                 + '<circle cx="50" cy="6" r="4" fill="' + palette[0] + '"/>'
                 + '<rect x="20" y="20" width="60" height="55" rx="12" fill="' + palette[0] + '"/>'
                 + '<rect x="12" y="38" width="8" height="18" rx="4" fill="' + palette[1] + '"/>'
                 + '<rect x="80" y="38" width="8" height="18" rx="4" fill="' + palette[1] + '"/>'
                 + eyes(38, 62, 42, 6 + pick(3), eyeColor)
                 + '<rect x="36" y="58" width="28" height="' + (5 + pick(6)) + '" rx="3" fill="' + palette[1] + '"/>';
        },

        'fun-emoji': function (pick, palette) {
            return '<circle cx="50" cy="50" r="42" fill="' + palette[0] + '"/>'
                 + eyes(37, 63, 42, 5 + pick(4), '#0f172a')
                 + mouth(pick(4), '#0f172a');
        },

        'adventurer': function (pick, palette, skin, hair) {
            return '<circle cx="50" cy="50" r="44" fill="' + palette[0] + '" opacity="0.25"/>'
                 + '<path d="M22 46 Q50 10 78 46 L78 40 Q50 18 22 40 Z" fill="' + hair + '"/>'
                 + '<circle cx="50" cy="52" r="28" fill="' + skin + '"/>'
                 + '<path d="M22 46 Q50 14 78 46 Q64 30 50 30 Q36 30 22 46 Z" fill="' + hair + '"/>'
                 + eyes(41, 59, 50, 3 + pick(2), '#1f2937')
                 + mouth(pick(2), '#7f1d1d');
        },

        'lorelei': function (pick, palette, skin, hair) {
            return '<rect width="100" height="100" rx="50" fill="' + palette[0] + '" opacity="0.2"/>'
                 + '<ellipse cx="50" cy="54" rx="26" ry="30" fill="' + skin + '"/>'
                 + '<path d="M24 48 Q50 8 76 48 Q76 24 50 24 Q24 24 24 48 Z" fill="' + hair + '"/>'
                 + '<path d="M24 48 Q20 70 26 82" stroke="' + hair + '" stroke-width="7" fill="none" stroke-linecap="round"/>'
                 + '<path d="M76 48 Q80 70 74 82" stroke="' + hair + '" stroke-width="7" fill="none" stroke-linecap="round"/>'
                 + eyes(41, 59, 52, 3, '#1f2937')
                 + mouth(pick(2), '#9f1239');
        }
    };

    /**
     * Liefert einen Avatar als Data-URI.
     *
     * @param {string} style Einer der Werte aus STYLES
     * @param {string} seed  Beliebiger Text; gleicher Seed = gleicher Avatar
     */
    function localAvatar(style, seed) {
        var pick = picker(seed);
        var palette = PALETTES[hash(seed) % PALETTES.length];
        var skin = SKINS[pick(SKINS.length)];
        var hair = HAIR[pick(HAIR.length)];
        var render = RENDERERS[style] || RENDERERS.adventurer;

        return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg(render(pick, palette, skin, hair)));
    }

    global.localAvatar = localAvatar;
    global.localAvatarStyles = STYLES;
})(window);
