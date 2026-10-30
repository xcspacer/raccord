// Runs the phone's rules (resources/js/audit/rules.js) on the boxes read from the standard input, for the parity test.
import { readFileSync } from 'node:fs';
import { boxItems, boxPercent, isFinal, requiredFolders } from '../../resources/js/audit/rules.js';

const cases = JSON.parse(readFileSync(0, 'utf8'));

console.log(JSON.stringify(cases.map(({ box, mode }) => ({
    percent: boxPercent(box, mode),
    items: boxItems(box, mode),
    folders: requiredFolders(box, mode, box.photos.some((photo) => photo.folder === 'tco')),
    final: isFinal(box),
}))));
