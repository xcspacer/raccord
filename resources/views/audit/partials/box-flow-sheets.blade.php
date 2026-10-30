<template x-if="isSheet('flowSubmit')">
    <div>
        <h2 class="site-screen__label" x-text="ft('submit_title')"></h2>
        <p class="site-screen__muted" x-text="ft('submit_hint')"></p>
        <p class="site-screen__muted" x-show="flowUnknownMissing" x-text="ft('missing_unknown')"></p>
        <template x-if="flowMissingShown">
            <div class="box-flow__proofs">
                <p x-text="ft('missing_title')"></p>
                <template x-for="folder in flowMissing" x-bind:key="folder.folder">
                    <label class="box-flow__proof">
                        <strong x-text="folder.label"></strong>
                        <small class="site-screen__muted" x-text="flowFolderHint(folder)"></small>
                        <textarea class="site-screen__input" rows="2" maxlength="2000" x-show="!folder.waiting" x-bind:value="flowJustification(folder)" x-on:input="setFlowJustification(folder, $event)"></textarea>
                    </label>
                </template>
            </div>
        </template>
        <div class="site-screen__row"><button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('cancel')"></button><button type="button" class="btn btn--primary" x-on:click="flowConfirmSubmit" x-text="ft('submit_confirm')"></button></div>
    </div>
</template>
<template x-if="isSheet('flowHandOver')">
    <div>
        <h2 class="site-screen__label" x-text="ft('hand_over_title')"></h2>
        <p class="site-screen__muted" x-text="flowHandOverHint"></p>
        <template x-for="person in flowPartners" x-bind:key="person.id">
            <button type="button" class="site-screen__shot" x-on:click="flowHandOver(person)" x-text="person.name"></button>
        </template>
        <button type="button" class="site-screen__shot site-screen__shot--dashed" x-on:click="flowAskSupervisor" x-text="flowAskSupervisorText"></button>
        <button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('cancel')"></button>
    </div>
</template>
<template x-if="isSheet('occCreate')">
    <div>
        <h2 class="site-screen__label" x-text="ft('create_title')"></h2>
        <label class="box-flow__proof">
            <span x-text="ft('type')"></span>
            <select class="site-screen__input" x-model="occDraft.type">
                <option value="" x-text="ft('choose')"></option>
                <template x-for="type in occTypes" x-bind:key="type.id"><option x-bind:value="type.id" x-text="type.label"></option></template>
            </select>
        </label>
        <label class="box-flow__proof"><span x-text="ft('description')"></span><textarea class="site-screen__input" rows="3" maxlength="2000" x-model="occDraft.description"></textarea></label>
        <label class="box-flow__proof"><span x-text="ft('location')"></span><input class="site-screen__input" maxlength="500" x-model="occDraft.location"></label>
        <p x-text="ft('before_photos')"></p>
        @include('audit.partials.box-flow-picker')
        <div class="site-screen__row"><button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('cancel')"></button><button type="button" class="btn btn--primary" x-on:click="occCreate" x-text="ft('save')"></button></div>
    </div>
</template>
<template x-if="isSheet('occHold')">
    <div>
        <h2 class="site-screen__label" x-text="occSheetTitle"></h2>
        <label class="box-flow__proof"><span x-text="ft('hold_justification')"></span><textarea class="site-screen__input" rows="3" maxlength="2000" x-model="occForm.justification"></textarea></label>
        <div class="site-screen__row"><button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('cancel')"></button><button type="button" class="btn btn--primary" x-on:click="occHold" x-text="ft('save')"></button></div>
    </div>
</template>
<template x-if="isSheet('occCorrect')">
    <div>
        <h2 class="site-screen__label" x-text="occSheetTitle"></h2>
        <label class="box-flow__proof"><span x-text="ft('correct_action')"></span><textarea class="site-screen__input" rows="2" maxlength="2000" x-model="occForm.action"></textarea></label>
        <label class="box-flow__proof"><span x-text="ft('correct_checks')"></span><textarea class="site-screen__input" rows="2" maxlength="2000" x-model="occForm.checks"></textarea></label>
        <label class="box-flow__proof"><span x-text="ft('correct_observations')"></span><textarea class="site-screen__input" rows="2" maxlength="2000" x-model="occForm.observations"></textarea></label>
        <p x-text="ft('after_photos')"></p>
        @include('audit.partials.box-flow-picker')
        <div class="site-screen__row"><button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('cancel')"></button><button type="button" class="btn btn--primary" x-on:click="occCorrect" x-text="ft('save')"></button></div>
    </div>
</template>
<template x-if="isSheet('occLink')">
    <div>
        <h2 class="site-screen__label" x-text="occSheetTitle"></h2>
        <label class="box-flow__proof">
            <span x-text="ft('role')"></span>
            <select class="site-screen__input" x-model="occForm.role">
                <template x-for="role in occRoles" x-bind:key="role"><option x-bind:value="role" x-text="occRoleLabel(role)"></option></template>
            </select>
        </label>
        @include('audit.partials.box-flow-picker')
        <div class="site-screen__row"><button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('cancel')"></button><button type="button" class="btn btn--primary" x-on:click="occLink" x-text="ft('save')"></button></div>
    </div>
</template>
<template x-if="isSheet('occBox')">
    <div>
        <h2 class="site-screen__label" x-text="occSheetTitle"></h2>
        <label class="box-flow__proof">
            <span x-text="ft('impacted_box')"></span>
            <select class="site-screen__input" x-model="occForm.box">
                <option value="" x-text="ft('choose')"></option>
                <template x-for="other in occOtherBoxes" x-bind:key="other.id"><option x-bind:value="other.id" x-text="other.name"></option></template>
            </select>
        </label>
        <label class="box-flow__proof"><span x-text="ft('impact_justification')"></span><textarea class="site-screen__input" rows="3" maxlength="2000" x-model="occForm.justification"></textarea></label>
        <div class="site-screen__row"><button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('cancel')"></button><button type="button" class="btn btn--primary" x-on:click="occPropose" x-text="ft('save')"></button></div>
    </div>
</template>
<template x-if="isSheet('occResult')">
    <div>
        <h2 class="site-screen__label" x-text="occSheetTitle"></h2>
        <div class="site-screen__row">
            <template x-for="option in occConformities" x-bind:key="option">
                <button type="button" class="site-screen__segment" x-bind:class="occConformityClass(option)" x-on:click="occSetConformity(option)" x-text="occConformityLabel(option)"></button>
            </template>
        </div>
        <label class="box-flow__proof"><span x-text="ft('result_note')"></span><textarea class="site-screen__input" rows="2" maxlength="1000" x-model="occForm.note"></textarea></label>
        <div class="site-screen__row"><button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('cancel')"></button><button type="button" class="btn btn--primary" x-on:click="occResult" x-text="ft('save')"></button></div>
    </div>
</template>
<template x-if="isSheet('synoptic')">
    <div>
        <h2 class="site-screen__label" x-text="ft('synoptic_propose')"></h2>
        <label class="box-flow__proof"><span x-text="ft('synoptic_text')"></span><textarea class="site-screen__input" rows="3" maxlength="2000" x-model="synDraft"></textarea></label>
        <p x-text="ft('synoptic_photo')"></p>
        @include('audit.partials.box-flow-picker')
        <div class="site-screen__row"><button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('cancel')"></button><button type="button" class="btn btn--primary" x-on:click="synopticSend" x-text="ft('save')"></button></div>
    </div>
</template>
