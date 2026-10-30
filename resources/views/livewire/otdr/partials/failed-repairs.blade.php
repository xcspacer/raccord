<section class="otdr__card">
    <h2 class="otdr__heading" x-text="repairsHeading"></h2>
    <p class="otdr__muted otdr__note-first">{{ __('otdr.failed_repairs.intro') }}</p>
    <div class="otdr__row">
        <label x-bind:class="repairsBusy ? 'otdr__btn is-busy' : 'otdr__btn'">
            <span x-show="!repairsBusy">{{ __('otdr.failed_repairs.import') }}</span>
            <span x-show="repairsBusy" x-cloak>{{ __('otdr.failed_repairs.reading') }}</span>
            <input type="file" accept=".xlsx,.csv,.xls" hidden x-bind:disabled="repairsBusy" x-on:change="importRepairs($event)">
        </label>
        <button type="button" class="otdr__btn" x-bind:disabled="failedRepairs.length === 0" x-on:click="downloadRepairs">{{ __('otdr.failed_repairs.download') }}</button>
    </div>
    <ul class="otdr__warnings otdr__repair-messages" x-show="repairsMessages.length > 0">
        <template x-for="(text, index) in repairsMessages" x-bind:key="index">
            <li x-text="text"></li>
        </template>
    </ul>
    <ul class="otdr__todo otdr__repairs" x-show="views.repairs.length > 0">
        <template x-for="item in views.repairs" x-bind:key="item.key">
            <li x-bind:class="item.ok ? 'otdr__todo-item is-neutralized' : 'otdr__todo-item'">
                <div class="otdr__todo-where" x-text="item.where"></div>
                <div class="otdr__todo-what" x-text="item.what"></div>
                <button type="button" class="otdr__link" x-on:click="removeRepair(item.key)">{{ __('otdr.failed_repairs.remove') }}</button>
            </li>
        </template>
    </ul>
</section>
