<section class="site-screen__block">
    <h2 class="site-screen__label" x-text="t('messages')"></h2>
    <div class="site-screen__chat">
        <template x-for="message in messages" x-bind:key="messageKey(message)">
            <div class="site-screen__bubble" x-bind:class="bubbleClass(message)">
                <small x-text="bubbleHeader(message)"></small>
                <span x-text="message.text"></span>
                <button type="button" class="btn btn--ghost" x-show="canAcknowledge(message)" x-on:click="acknowledge(message)" x-text="acknowledgeText"></button>
                <small x-show="message.acknowledged" x-text="ackText(message)"></small>
            </div>
        </template>
    </div>
    <div class="site-screen__row">
        <input class="site-screen__input" maxlength="1000" x-model="drafts.message" x-bind:placeholder="messagePlaceholder" x-on:keydown.enter.prevent="sendMessage">
        <button type="button" class="btn btn--ghost" x-on:click="sendMessage" x-text="sendLabel"></button>
    </div>
</section>
