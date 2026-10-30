{{-- Design office workflow of the box (resources/js/audit/box-workflow.js). --}}
<section class="site-screen__block box-flow" x-show="flowShown">
    <div class="site-screen__row">
        <h2 class="site-screen__label" x-text="ft('title')"></h2>
        <span class="box-flow__state" x-bind:class="flowStateClass" x-text="flowStateText"></span>
    </div>
    <p class="site-screen__muted" x-show="flowKnown" x-text="flowTeamText"></p>
    <p class="site-screen__muted" x-show="flowSavedText" x-text="flowSavedText"></p>
    <div class="box-flow__notice box-flow__notice--wait" role="status" x-show="flowSubmitQueued" x-text="ft('not_sent_yet')"></div>
    <div class="box-flow__notice box-flow__notice--wait" role="status" x-show="flowClaimQueued" x-text="ft('claim_waiting')"></div>
    <div class="box-flow__notice box-flow__notice--wait" role="status" x-show="flowHandOverQueued" x-text="ft('hand_over_waiting')"></div>
    <div class="box-flow__notice box-flow__notice--alert" role="alert" x-show="flowBlockedText" x-text="flowBlockedText"></div>
    <div class="box-flow__notice box-flow__notice--alert" role="alert" x-show="flowBlockingText" x-text="flowBlockingText"></div>
    <div class="box-flow__notice" x-show="flowHandoverText" x-text="flowHandoverText"></div>
    <div class="box-flow__notice box-flow__notice--wait" x-show="flowRequestText" x-text="flowRequestText"></div>
    <div class="box-flow__decision" x-bind:class="flowDecisionClass" x-show="flowDecision">
        <strong x-text="flowDecisionTitle"></strong>
        <p x-show="flowDecisionReason" x-text="flowDecisionReason"></p>
        <ul class="box-flow__targets" x-show="flowTargets.length">
            <template x-for="target in flowTargets" x-bind:key="target"><li x-text="target"></li></template>
        </ul>
    </div>
    <div class="box-flow__repairs" x-show="flowRepairsShown">
        <h3 class="site-screen__sublabel" x-text="ft('repairs_title')"></h3>
        <p class="site-screen__muted" x-show="flowInterventionText" x-text="flowInterventionText"></p>
        <ul class="box-flow__targets">
            <template x-for="item in flowRepairItems" x-bind:key="item.id"><li x-text="flowRepairText(item)"></li></template>
        </ul>
        <div class="box-flow__notice box-flow__notice--wait" role="status" x-show="flowRepairsQueued" x-text="ft('not_sent_yet')"></div>
        <p class="site-screen__muted" x-show="flowRepairsDecisionText" x-text="flowRepairsDecisionText"></p>
        <button type="button" class="btn btn--primary" x-show="flowCanSubmitRepairs" x-on:click="flowSubmitRepairs" x-text="flowSubmitRepairsText"></button>
    </div>
    <div class="site-screen__row box-flow__actions" x-show="flowTechnician">
        <button type="button" class="btn btn--primary" x-show="flowCanClaim" x-on:click="flowClaim" x-text="flowClaimText"></button>
        <button type="button" class="btn btn--primary" x-show="flowCanSubmit" x-on:click="flowOpenSubmit" x-text="flowSubmitText"></button>
        <button type="button" class="btn btn--ghost" x-show="flowCanHandOver" x-on:click="flowOpenHandOver" x-text="flowHandOverText"></button>
    </div>
</section>
