{{-- Internal chat of the box in the design office workflow (never shown to the client). --}}
<section class="site-screen__block" x-show="flowShown">
    <h2 class="site-screen__label" x-text="flowChatTitle"></h2>
    <p class="site-screen__muted" x-text="flowChatHint"></p>
    <p class="site-screen__muted" x-show="flowNoMessages" x-text="flowEmptyText"></p>
    <div class="site-screen__chat">
        <template x-for="message in flowMessages" x-bind:key="message.key">
            <div class="site-screen__bubble" x-bind:class="flowBubbleClass(message)">
                <small x-text="flowBubbleHeader(message)"></small>
                <span x-text="message.body"></span>
                <button type="button" class="btn btn--ghost" x-show="message.photo_id" x-on:click="flowShowPhoto(message)" x-text="flowPhotoText"></button>
                <small x-show="message.resolved" x-text="flowResolvedText(message)"></small>
                <button type="button" class="btn btn--ghost" x-show="flowCanResolve(message)" x-on:click="flowResolve(message)" x-text="flowResolveText"></button>
            </div>
        </template>
    </div>
    <div class="box-flow__about" x-show="flowAboutPhoto">
        <span x-text="flowAboutPhotoText"></span>
        <button type="button" class="chip-delete" x-on:click="flowForgetPhoto" x-bind:aria-label="removeText">✕</button>
    </div>
    <div class="site-screen__row">
        <select class="site-screen__input box-flow__kind" x-show="flowOffice" x-model="flowDraft.kind" x-bind:aria-label="ft('kind')">
            <template x-for="kind in flowKinds" x-bind:key="kind"><option x-bind:value="kind" x-text="flowKindLabel(kind)"></option></template>
        </select>
        <input class="site-screen__input" maxlength="2000" x-model="flowDraft.body" x-bind:placeholder="flowPlaceholder" x-on:keydown.enter.prevent="flowSend">
        <button type="button" class="btn btn--ghost" x-on:click="flowSend" x-text="flowSendText"></button>
    </div>
</section>
