// Runs the presence rules of the phone (resources/js/audit/geo.js) on the cases read from the standard input.
import { readFileSync } from 'node:fs';
import { decidePresence, distance, manualDistance } from '../../resources/js/audit/geo.js';

const cases = JSON.parse(readFileSync(0, 'utf8'));

console.log(JSON.stringify(cases.map((item) => (item.kind === 'distance'
    ? distance(item.a, item.b)
    : item.kind === 'manual' ? manualDistance(item.fix, item.box) : decidePresence({ ...item.input })))));
