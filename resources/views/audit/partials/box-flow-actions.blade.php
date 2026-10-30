{{-- Occurrences, pauses, position and synoptic corrections of the box in the design office workflow (resources/js/audit/box-workflow-actions.js). --}}
<section class="site-screen__block box-flow" x-show="flowShown">
    <div class="site-screen__row">
        <h2 class="site-screen__label" x-text="ft('occurrences_title')"></h2>
        <button type="button" class="btn btn--ghost" x-show="occCanDeclare" x-on:click="occOpenCreate" x-text="occDeclareText"></button>
    </div>
    <p class="site-screen__muted" x-show="occEmpty" x-text="ft('occurrences_none')"></p>
    <div class="box-flow__notice box-flow__notice--wait" role="status" x-show="occActionsWaiting" x-text="occActionsWaiting"></div>
    <template x-for="item in occWaiting" x-bind:key="item.key">
        <div class="box-flow__occurrence is-pending"><span x-text="occWaitingText(item)"></span></div>
    </template>
    <template x-for="occurrence in occList" x-bind:key="occurrence.id">
        <div class="box-flow__occurrence" x-bind:class="occClass(occurrence)">
            <strong x-text="occTitle(occurrence)"></strong>
            <p x-text="occurrence.description"></p>
            <template x-for="line in occDetails(occurrence)" x-bind:key="line"><small class="site-screen__muted" x-text="line"></small></template>
            <div class="box-flow__actions">
                <button type="button" class="btn btn--ghost btn--small" x-show="occCan(occurrence, 'correct')" x-on:click="occOpen('occCorrect', occurrence)" x-text="occCorrectText"></button>
                <button type="button" class="btn btn--ghost btn--small" x-show="occCan(occurrence, 'hold')" x-on:click="occOpen('occHold', occurrence)" x-text="occHoldText"></button>
                <button type="button" class="btn btn--ghost btn--small" x-show="occCan(occurrence, 'link')" x-on:click="occOpen('occLink', occurrence)" x-text="occLinkText"></button>
                <button type="button" class="btn btn--ghost btn--small" x-show="occCan(occurrence, 'propose')" x-on:click="occOpen('occBox', occurrence)" x-text="occProposeText"></button>
                <button type="button" class="btn btn--ghost btn--small" x-show="occCan(occurrence, 'result')" x-on:click="occOpen('occResult', occurrence)" x-text="occResultText"></button>
            </div>
        </div>
    </template>

    <div class="box-flow__extras">
        <div class="box-flow__notice" role="status" x-show="pauseText" x-text="pauseText"></div>
        <div class="box-flow__notice" x-show="positionProposalText" x-text="positionProposalText"></div>
        <div class="box-flow__notice box-flow__notice--wait" role="status" x-show="positionQueued" x-text="ft('message_waiting')"></div>
        <div class="site-screen__row box-flow__actions">
            <button type="button" class="btn btn--ghost" x-show="canPause" x-bind:disabled="pauseBusy" x-on:click="pauseStart" x-text="pauseButtonText"></button>
            <button type="button" class="btn btn--primary" x-show="canResume" x-bind:disabled="pauseBusy" x-on:click="pauseEnd" x-text="resumeButtonText"></button>
            <button type="button" class="btn btn--ghost" x-show="canProposePosition" x-on:click="proposePosition" x-text="proposePositionText"></button>
            <button type="button" class="btn btn--ghost" x-show="canProposeSynoptic" x-on:click="synopticOpen" x-text="synopticProposeText"></button>
        </div>
        <template x-for="item in synopticWaiting" x-bind:key="item.key"><small class="site-screen__muted" x-text="synopticWaitingText(item)"></small></template>
        <template x-for="correction in synopticList" x-bind:key="correction.id">
            <div class="box-flow__synoptic">
                <small x-text="synopticText(correction)"></small>
                <small class="site-screen__muted" x-show="correction.decision_note" x-text="synopticNote(correction)"></small>
            </div>
        </template>
    </div>
</section>
