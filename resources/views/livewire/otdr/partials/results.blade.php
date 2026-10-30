<template x-if="noFibers">
    <section class="otdr__card">
        <h2 class="otdr__heading">{{ __('otdr.results.heading') }}</h2>
        <p>{{ __('otdr.results.no_measure') }}</p>
        <ul class="otdr__warnings" x-show="views.warnings.length > 0">
            <template x-for="(text, index) in views.warnings" x-bind:key="index">
                <li x-text="text"></li>
            </template>
        </ul>
    </section>
</template>

<template x-if="hasFibers">
    <div>
        <section class="otdr__card">
            <div class="otdr__light">
                <span x-bind:class="views.light.dotClass" aria-hidden="true"></span>
                <div>
                    <div class="otdr__status"><span x-text="views.light.text"></span> <span class="otdr__muted" x-text="views.light.status"></span></div>
                    <div class="otdr__muted" x-text="views.light.hint"></div>
                </div>
            </div>
            <ul class="otdr__criteria">
                <template x-for="criterion in views.criteria" x-bind:key="criterion.key">
                    <li x-bind:class="criterion.className">
                        <span class="otdr__pip" aria-hidden="true"></span>
                        <span><span class="otdr__criterion-label" x-text="criterion.label"></span> <span class="otdr__criterion-detail" x-text="criterion.detail"></span></span>
                    </li>
                </template>
            </ul>
        </section>

        <section class="otdr__card">
            <h2 class="otdr__heading" x-text="todoHeading"></h2>
            <p class="otdr__flush" x-show="views.todo.items.length === 0">{{ __('otdr.results.no_out_of_limits') }}</p>
            <ul class="otdr__todo" x-show="views.todo.items.length > 0">
                <template x-for="item in views.todo.items" x-bind:key="item.key">
                    <li x-bind:class="item.className">
                        <div class="otdr__todo-where" x-text="item.where"></div>
                        <div class="otdr__todo-what"><span x-text="item.subject"></span> <span class="otdr__num" x-text="item.value"></span> <span x-text="item.note"></span></div>
                        <div class="otdr__row otdr__row--wide otdr__todo-actions">
                            <button type="button" class="otdr__link" x-show="item.canViewTrace" x-on:click="viewAnomalyTrace(item.index)">{{ __('otdr.results.view_trace') }}</button>
                            <button type="button" class="otdr__link" x-show="item.canAddFailedRepair" x-on:click="addAnomalyRepair(item.index)">{{ __('otdr.results.add_failed_repair') }}</button>
                        </div>
                    </li>
                </template>
            </ul>
        </section>

        <section class="otdr__card">
            <h2 class="otdr__heading">{{ __('otdr.results.indicators') }}</h2>
            <dl class="otdr__kv">
                <template x-for="indicator in views.indicators" x-bind:key="indicator.key">
                    <div>
                        <dt x-text="indicator.label"></dt>
                        <dd x-text="indicator.value"></dd>
                    </div>
                </template>
            </dl>
        </section>

        <section class="otdr__card">
            <h2 class="otdr__heading" x-text="detailHeading"></h2>
            <template x-for="group in views.detail" x-bind:key="group.key">
                <details class="otdr__group-detail" x-bind:open="isGroupOpen(group.key)" x-on:toggle="toggleGroup(group.key, $event)">
                    <summary>
                        <span class="otdr__group-name" x-text="group.name"></span>
                        <span class="otdr__badge" x-text="group.fibersBadge"></span>
                        <span class="otdr__badge otdr__badge--bad" x-show="group.outBadge !== ''" x-text="group.outBadge"></span>
                    </summary>
                    <template x-if="isGroupOpen(group.key)">
                        <div class="otdr__group-body">
                            <div class="otdr__table-wrap">
                                <table class="otdr__table">
                                    <thead>
                                        <tr>
                                            <th>{{ __('otdr.results.column_fiber') }}</th>
                                            <th>{{ __('otdr.results.column_wavelength') }}</th>
                                            <template x-for="header in group.headers" x-bind:key="header.key">
                                                <th x-text="header.label"></th>
                                            </template>
                                            <th>{{ __('otdr.results.column_total_loss') }}</th>
                                            <th>{{ __('otdr.results.column_length') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="row in group.rows" x-bind:key="row.key">
                                            <tr>
                                                <td><button type="button" class="otdr__link" title="{{ __('otdr.results.view_trace') }}" x-on:click="openRowTrace(row)" x-text="row.label"></button></td>
                                                <td x-text="row.lambda"></td>
                                                <template x-for="cell in row.cells" x-bind:key="cell.key">
                                                    <td x-bind:class="cell.className" x-bind:title="cell.title" x-text="cell.text"></td>
                                                </template>
                                                <td x-bind:title="row.totalLossTitle" x-text="row.totalLoss"></td>
                                                <td x-text="row.length"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <ul class="otdr__warnings" x-show="group.warnings.length > 0">
                                <template x-for="(text, index) in group.warnings" x-bind:key="index">
                                    <li x-text="text"></li>
                                </template>
                            </ul>
                        </div>
                    </template>
                </details>
            </template>
            <p class="otdr__muted otdr__legend">
                <span x-show="isBidirectional">{{ __('otdr.results.legend_bidirectional') }}</span>
                <span x-show="!isBidirectional">{{ __('otdr.results.legend_unidirectional') }}</span>
                <span class="otdr__value--out">{{ __('otdr.results.legend_out') }}</span> {{ __('otdr.results.legend_out_text') }}
                <span class="otdr__value--gain">{{ __('otdr.results.legend_gain') }}</span> {{ __('otdr.results.legend_gain_text') }}
                <span class="otdr__value--neutralized">{{ __('otdr.results.legend_neutralized') }}</span> {{ __('otdr.results.legend_neutralized_text') }}
            </p>
        </section>

        <template x-if="views.warnings.length > 0">
            <section class="otdr__card">
                <h2 class="otdr__heading">{{ __('otdr.results.warnings') }}</h2>
                <ul class="otdr__warnings">
                    <template x-for="(text, index) in views.warnings" x-bind:key="index">
                        <li x-text="text"></li>
                    </template>
                </ul>
            </section>
        </template>
    </div>
</template>
