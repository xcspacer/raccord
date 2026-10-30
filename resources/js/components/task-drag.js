/**
 * Drag and drop on the task grid, with Pointer Events (mouse and touch).
 *  - a task cell dropped on another cell copies it (Shift: moves it); the server checks everything;
 *  - a row handle dropped between rows reorders the people.
 * Mouse starts after 6 px of movement, touch after a 400 ms hold (moving more than 10 px first cancels it,
 * so the page can still scroll). Esc cancels. Phones (<= 640 px) do not drag.
 */
const MOUSE_THRESHOLD = 6;
const TOUCH_HOLD_MS = 400;
const TOUCH_TOLERANCE = 10;

let state = null;
let suppressClick = false;

const component = (element) => {
    const root = element.closest('[wire\\:id]');

    return root ? window.Livewire.find(root.getAttribute('wire:id')) : null;
};

const clearMarks = () => {
    document.querySelectorAll('.is-drop-target, .is-drag-source, .is-dragging, .is-drop-before, .is-drop-after').forEach((element) => {
        element.classList.remove('is-drop-target', 'is-drag-source', 'is-dragging', 'is-drop-before', 'is-drop-after');
    });
};

const stop = () => {
    if (state) {
        clearTimeout(state.timer);
        state.ghost?.remove();
    }

    clearMarks();
    state = null;
};

const moveGhost = (event) => {
    state.ghost.style.setProperty('--ghost-x', `${event.clientX + 12}px`);
    state.ghost.style.setProperty('--ghost-y', `${event.clientY + 12}px`);
};

const begin = (event) => {
    state.active = true;

    if (state.kind === 'task') {
        state.ghost = document.createElement('div');
        state.ghost.className = 'task-ghost';
        state.ghost.textContent = state.source.querySelector('.task-label')?.textContent?.trim() || state.source.textContent.trim().slice(0, 60);
        document.body.appendChild(state.ghost);
        state.source.classList.add('is-drag-source');
        moveGhost(event);
    } else {
        state.row.classList.add('is-dragging');
    }
};

const hover = (event) => {
    document.querySelectorAll('.is-drop-target, .is-drop-before, .is-drop-after').forEach((element) => {
        element.classList.remove('is-drop-target', 'is-drop-before', 'is-drop-after');
    });

    const under = document.elementFromPoint(event.clientX, event.clientY);

    if (state.kind === 'task') {
        const cell = under?.closest('.tasks-cell[data-user-id]');
        state.target = cell && cell !== state.source ? cell : null;
        state.target?.classList.add('is-drop-target');

        return;
    }

    const row = under?.closest('tr[data-person-id]');
    state.target = row && row !== state.row ? row : null;

    if (state.target) {
        const box = state.target.getBoundingClientRect();
        state.after = event.clientY > box.top + box.height / 2;
        state.target.classList.add(state.after ? 'is-drop-after' : 'is-drop-before');
    }
};

const drop = (event) => {
    const { kind, target, source, row, after } = state;
    const shift = event.shiftKey;
    const host = component(kind === 'task' ? source : row);

    if (target && host) {
        if (kind === 'task') {
            host.call('dropTask', Number(source.dataset.taskId), Number(target.dataset.userId), target.dataset.date, shift, Number(source.dataset.taskVersion));
        } else {
            const ids = [...row.parentElement.querySelectorAll('tr[data-person-id]')].map((item) => Number(item.dataset.personId)).filter((id) => id !== Number(row.dataset.personId));
            const index = ids.indexOf(Number(target.dataset.personId)) + (after ? 1 : 0);
            ids.splice(index, 0, Number(row.dataset.personId));
            host.call('reorder', ids);
        }
    }
};

export function initTaskDrag() {
    const phone = window.matchMedia('(max-width: 640px)');

    document.addEventListener('pointerdown', (event) => {
        if (phone.matches || (event.pointerType === 'mouse' && event.button !== 0) || state) {
            return;
        }

        const handle = event.target.closest('[data-row-handle]');
        const cell = handle ? null : event.target.closest('.tasks-cell[data-task-id]');

        if (!handle && !cell) {
            return;
        }

        state = {
            kind: handle ? 'row' : 'task',
            source: cell,
            row: handle?.closest('tr'),
            startX: event.clientX,
            startY: event.clientY,
            touch: event.pointerType !== 'mouse',
            active: false,
            target: null,
        };

        if (state.touch && !handle) {
            state.timer = setTimeout(() => state && begin(event), TOUCH_HOLD_MS);
        }
    });

    document.addEventListener('pointermove', (event) => {
        if (!state) {
            return;
        }

        const distance = Math.hypot(event.clientX - state.startX, event.clientY - state.startY);

        if (!state.active) {
            if (state.touch && state.kind === 'task' && distance > TOUCH_TOLERANCE) {
                stop();
            } else if ((!state.touch || state.kind === 'row') && distance > MOUSE_THRESHOLD) {
                begin(event);
            }

            return;
        }

        state.ghost && moveGhost(event);
        hover(event);
    });

    document.addEventListener('touchmove', (event) => {
        if (state?.active) {
            event.preventDefault();
        }
    }, { passive: false });

    document.addEventListener('pointerup', (event) => {
        if (!state) {
            return;
        }

        if (state.active) {
            suppressClick = true;
            setTimeout(() => { suppressClick = false; }, 0);
            drop(event);
        }

        stop();
    });

    document.addEventListener('pointercancel', stop);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && state) {
            stop();
        }
    });

    document.addEventListener('click', (event) => {
        if (suppressClick) {
            event.stopPropagation();
            event.preventDefault();
        }
    }, true);
}
