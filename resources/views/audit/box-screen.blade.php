<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-partials.head-meta :title="__('audit.field.title')" />
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="field-body" data-user-id="{{ auth()->id() }}">
    {{-- Alpine runs in its CSP-safe build: expressions stay simple (properties, calls, !, &&), the logic lives in resources/js/audit/box-screen.js. --}}
    <div class="site-screen" data-audit-box x-data="auditBox" data-config="{{ json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" x-cloak>
        <header class="site-screen__top">
            <button type="button" class="btn btn--ghost" x-on:click="leave" x-text="leaveText"></button>
            <span class="site-screen__chip" x-bind:class="chipClass" x-text="chipText" x-on:click="openRefused"></span>
        </header>

        <div class="field-warning" role="alert" x-show="memoryOnly" x-text="memoryText"></div>
        <div class="field-warning" role="alert" x-show="stalePhotos" x-text="stalePhotosText"></div>
        <div class="field-warning" role="alert" x-show="updateProblem" x-text="updateText"></div>
        <div class="field-warning" role="alert" x-show="authProblem">
            <span x-text="authText"></span> <a class="btn btn--ghost" x-bind:href="loginUrl" x-text="signInText"></a>
        </div>

        <p x-show="!ready" x-text="loadingText"></p>
        <p x-show="ready && !view" x-text="offlineText"></p>
        <p x-show="ready && view && !box" x-text="unavailableText"></p>

        <template x-if="box">
            <main class="site-screen__box">
                <div class="site-screen__head">
                    <div>
                        <h1 class="site-screen__name" x-text="box.name"></h1>
                        <span class="site-screen__muted" x-text="subtitle"></span>
                    </div>
                    <strong class="site-screen__percent" x-text="percentText"></strong>
                </div>
                <div class="audit__bar" role="progressbar" x-bind:aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100"><span class="audit__bar-fill" x-effect="paint($el, percent)"></span></div>

                <section class="audit__todo" x-show="todo.length > 0">
                    <strong class="audit__todo-title" x-text="t('todo_title')"></strong>
                    <ul class="audit__todo-list">
                        <template x-for="item in todo" x-bind:key="item">
                            <li x-text="item"></li>
                        </template>
                    </ul>
                </section>

                <div class="site-screen__row" x-show="manager">
                    <button type="button" class="btn btn--ghost site-screen__edit" x-show="canEditMode" x-on:click="startEditing" x-text="editText"></button>
                    <button type="button" class="btn btn--primary" x-show="editing" x-on:click="stopEditing" x-text="finishEditText"></button>
                    <button type="button" class="btn btn--ghost" x-show="canSelect" x-on:click="toggleSelecting" x-text="selectText"></button>
                </div>
                <div class="audit__banner audit__banner--partial" x-show="editing" x-text="t('editing_banner')"></div>

                <button type="button" class="site-screen__shot" x-show="navigable" x-on:click="openNavigation" x-text="navigateText"></button>

                <div class="audit__banner audit__banner--ok" x-show="validatedBanner" x-text="validatedText"></div>
                <div class="audit__banner audit__banner--returned" x-show="box.returned" x-text="returnedText"></div>

                <template x-for="who in others" x-bind:key="who.user_id">
                    <span class="audit__who" x-bind:class="whoClass(who)" x-text="whoText(who)"></span>
                </template>

                @include('audit.partials.box-flow')
                @include('audit.partials.box-flow-actions')

                @include('audit.partials.client-notes')

                <template x-if="!flowShown">
                    @include('audit.partials.messages')
                </template>
                @include('audit.partials.box-flow-chat')

                <section class="site-screen__block" x-show="technician && !blocked">
                    <div class="site-screen__row">
                        <button type="button" class="site-screen__big" x-bind:class="presenceClass('en_route')" x-on:click="announce('en_route')" x-text="goingText"></button>
                        <button type="button" class="site-screen__big" x-bind:class="presenceClass('on_box')" x-on:click="announce('on_box')" x-text="arrivingText"></button>
                    </div>
                </section>

                <section class="site-screen__block">
                    <h2 class="site-screen__label" x-text="t('support')"></h2>
                    <div class="site-screen__segmented">
                        <template x-for="option in supportOptions" x-bind:key="option">
                            <button type="button" class="site-screen__segment" x-bind:class="segmentClass(box.support, option)" x-bind:disabled="blocked" x-on:click="setSupport(option)" x-text="supportLabel(option)"></button>
                        </template>
                    </div>
                    <input class="site-screen__input" x-bind:value="supportNumberValue" x-bind:placeholder="supportPlaceholder" x-bind:disabled="blocked" maxlength="30" x-on:change="onSupportNumber($event)">
                </section>

                <div class="audit__banner" x-show="notInstalled" x-text="notInstalledText"></div>

                <template x-if="!notInstalled">
                    <div class="site-screen__sections">
                        <section class="site-screen__block" x-show="full">
                            <h2 class="site-screen__label" x-text="t('fibre')"></h2>
                            <div class="site-screen__segmented">
                                <template x-for="option in fibreOptions" x-bind:key="option">
                                    <button type="button" class="site-screen__segment" x-bind:class="segmentClass(box.fibre_type, option)" x-bind:disabled="blocked" x-on:click="setFibre(option)" x-text="fibreLabel(option)"></button>
                                </template>
                            </div>
                        </section>

                        <section class="site-screen__block" x-show="optical">
                            <h2 class="site-screen__label" x-text="t('cables')"></h2>
                            <template x-for="(cable, index) in box.cables" x-bind:key="cable.code">
                                <article class="site-screen__cable" x-bind:class="cableClass(cable)">
                                    <div class="site-screen__row">
                                        <strong x-text="cableTitle(cable, index)"></strong>
                                        <button type="button" class="chip-delete" x-bind:disabled="blocked" x-on:click="removeCable(cable.code)" x-bind:aria-label="removeText">✕</button>
                                    </div>
                                    <h3 class="site-screen__sublabel" x-text="t('cable_label')"></h3>
                                    <label class="site-screen__check"><input type="checkbox" x-bind:checked="cable.has_no_label" x-bind:disabled="blocked" x-on:change="onCableNoLabel(cable, $event)"> <span x-text="t('no_label')"></span></label>
                                    <div x-show="!cable.has_no_label">
                                        <button type="button" class="site-screen__shot" x-bind:disabled="photoBlocked" x-on:click="takeCablePhoto('labels', cable)" x-text="shotText"></button>
                                        @include('audit.partials.photos', ['photos' => 'cablePhotos(cable.code, \'labels\')'])
                                    </div>
                                    <h3 class="site-screen__sublabel" x-text="t('lashing')"></h3>
                                    <button type="button" class="site-screen__shot" x-bind:disabled="photoBlocked" x-on:click="takeCablePhoto('lashing', cable)" x-text="shotText"></button>
                                    @include('audit.partials.photos', ['photos' => 'cablePhotos(cable.code, \'lashing\')'])
                                    <h3 class="site-screen__sublabel" x-text="t('cable_info')"></h3>
                                    <textarea class="site-screen__input" rows="2" maxlength="500" x-bind:disabled="blocked" x-bind:value="cableNote(cable)" x-on:change="onCableNote(cable, $event)"></textarea>
                                    <div class="site-screen__row">
                                        <label class="site-screen__col"><span class="site-screen__sublabel" x-text="t('capacity')"></span>
                                            <select class="site-screen__input" x-bind:disabled="blocked" x-on:change="onCableCapacity(cable, $event)">
                                                <option value="">—</option>
                                                <template x-for="capacity in capacities" x-bind:key="capacity"><option x-bind:value="capacity" x-bind:selected="cable.capacity === capacity" x-text="capacity"></option></template>
                                            </select>
                                        </label>
                                        <div class="site-screen__col"><span class="site-screen__sublabel" x-text="t('fibre')"></span>
                                            <div class="site-screen__segmented">
                                                <template x-for="option in fibreOptions" x-bind:key="option">
                                                    <button type="button" class="site-screen__segment" x-bind:class="segmentClass(cable.fibre_type, option)" x-bind:disabled="blocked" x-on:click="toggleCableFibre(cable, option)" x-text="fibreLabel(option)"></button>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            </template>
                            <button type="button" class="site-screen__shot site-screen__shot--dashed" x-bind:disabled="blocked" x-on:click="addCable" x-text="addCableText"></button>
                        </section>

                        <section class="site-screen__block" x-show="optical">
                            <h2 class="site-screen__label" x-text="positionTitle"></h2>
                            <p class="site-screen__muted" x-text="positionText"></p>
                            <button type="button" class="site-screen__shot" x-bind:disabled="blocked" x-on:click="confirmPosition" x-text="positionButtonText"></button>
                        </section>

                        <template x-for="folder in folders" x-bind:key="folder.key">
                            <section class="site-screen__block site-screen__folder" x-bind:class="folderClass(folder)" x-on:dragover="dragOver(folder.key, '', $event)" x-on:drop="dropPhotos(folder.key, '', $event)">
                                <div class="site-screen__row">
                                    <h2 class="site-screen__label"><i class="site-screen__tick" x-bind:class="tickClass(folder)"></i> <span x-text="folder.title"></span></h2>
                                    <span class="site-screen__muted" x-show="folder.validated && !manager" x-text="validatedMark"></span>
                                    <button type="button" class="btn btn--ghost" x-show="showValidate(folder)" x-on:click="validateFolder(folder)" x-text="validateFolderText(folder)"></button>
                                </div>
                                <template x-if="folder.key === 'before'">
                                    <div>
                                        <label class="site-screen__sublabel" x-text="t('before_comment')"></label>
                                        <textarea class="site-screen__input" rows="2" maxlength="2000" x-model="drafts.before" x-bind:disabled="blocked" x-on:change="saveNote('before')"></textarea>
                                    </div>
                                </template>
                                <template x-if="folder.key === 'pole'">
                                    <div>
                                        <template x-for="section in folder.sections" x-bind:key="section.key">
                                            <div class="site-screen__drop" x-bind:class="dropClass('pole', section.key)" x-on:dragover="dragOver('pole', section.key, $event)" x-on:drop="dropPhotos('pole', section.key, $event)">
                                                <h3 class="site-screen__sublabel" x-text="section.title"></h3>
                                                <button type="button" class="site-screen__shot" x-bind:disabled="photoBlocked" x-on:click="takePhoto('pole', section.key)" x-text="shotText"></button>
                                                @include('audit.partials.photos', ['photos' => 'section.photos'])
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="folder.key !== 'pole'">
                                    <div>
                                        <button type="button" class="site-screen__shot" x-bind:disabled="photoBlocked" x-on:click="takePhoto(folder.key, '')" x-text="shotText"></button>
                                        <label class="site-screen__shot site-screen__shot--dashed" x-show="showImport(folder)"><span x-text="importText"></span><input type="file" class="file-picker__input" accept="image/jpeg,image/png,image/webp" multiple x-on:change="importPhotos($event, folder)"></label>
                                        @include('audit.partials.photos', ['photos' => 'folder.photos'])
                                    </div>
                                </template>
                                <template x-if="folder.key === 'tco'">
                                    <div>
                                        <label class="site-screen__sublabel" x-text="t('tco_comment')"></label>
                                        <textarea class="site-screen__input" rows="2" maxlength="2000" x-model="drafts.tco" x-bind:disabled="blocked" x-on:change="saveNote('tco')"></textarea>
                                    </div>
                                </template>
                            </section>
                        </template>

                        <section class="site-screen__block" x-show="full">
                            <h2 class="site-screen__label" x-text="t('route_comment')"></h2>
                            <textarea class="site-screen__input" rows="2" maxlength="2000" x-model="drafts.route" x-bind:disabled="blocked" x-on:change="saveNote('route')"></textarea>
                        </section>

                        <section class="site-screen__block" x-show="optical && recheck">
                            <a class="site-screen__shot" x-bind:href="recheckUrl" x-text="recheckText"></a>
                        </section>

                        <section class="site-screen__block" x-show="recheckPaperShown">
                            <h2 class="site-screen__label" x-text="t('recheck_paper')"></h2>
                            <button type="button" class="site-screen__shot" x-bind:disabled="photoBlocked" x-on:click="takePhoto('recheck', '')" x-text="shotText"></button>
                            @include('audit.partials.photos', ['photos' => 'recheckPhotos'])
                        </section>

                        <section class="site-screen__block" x-show="reportShown">
                            <button type="button" class="site-screen__shot" x-bind:disabled="blocked" x-on:click="openReport" x-text="reportButtonText"></button>
                        </section>
                    </div>
                </template>

                <section class="site-screen__block">
                    <h2 class="site-screen__label" x-text="t('final_status')"></h2>
                    <div class="site-screen__statuses">
                        <template x-for="option in statuses" x-bind:key="option">
                            <button type="button" class="site-screen__status" x-bind:class="statusClass(option)" x-bind:disabled="blocked" x-on:click="chooseStatus(option)">
                                <span x-text="statusIcon(option)"></span>
                                <span x-text="statusLabel(option)"></span>
                            </button>
                        </template>
                    </div>
                    <p class="site-screen__muted" x-show="reasonShown" x-text="reasonText"></p>
                </section>

                <section class="site-screen__block" x-show="manager">
                    <button type="button" class="btn btn--ghost" x-on:click="askReturn" x-text="returnText"></button>
                    <button type="button" class="btn btn--ghost" x-on:click="askPdf" x-text="pdfCreateText"></button>
                    <a class="btn btn--ghost" x-show="pdf" x-bind:href="pdfUrl" target="_blank" rel="noopener" x-text="pdfOpenText"></a>
                    <button type="button" class="btn btn--ghost btn--danger" x-show="editing" x-on:click="removeBox" x-text="removeBoxText"></button>
                </section>

                <section class="site-screen__block">
                    <button type="button" class="btn btn--ghost" x-on:click="openLocalPhotos" x-text="localPhotosText"></button>
                </section>

                <footer class="site-screen__footer" x-show="!showMoveBar">
                    <strong x-text="percentText"></strong>
                    <button type="button" class="btn btn--primary" x-on:click="leave" x-text="leaveBoxText"></button>
                </footer>
            </main>
        </template>

        <div class="site-screen__movebar" x-show="showMoveBar">
            <strong x-text="selectedText"></strong>
            <select class="site-screen__input" x-model="moveTarget" x-bind:aria-label="t('move_to')">
                <option value="" x-text="t('move_to')"></option>
                <template x-for="target in moveTargets" x-bind:key="target.value"><option x-bind:value="target.value" x-text="target.label"></option></template>
            </select>
            <button type="button" class="btn btn--primary" x-bind:disabled="noneSelected" x-on:click="movePhotos" x-text="t('move')"></button>
            <button type="button" class="btn btn--ghost" x-on:click="clearSelection" x-text="t('none')"></button>
            <button type="button" class="btn btn--ghost" x-on:click="leaveSelection" x-text="leaveSelectionText"></button>
        </div>

        <div class="site-screen__overlay" x-show="sheetOpen" x-on:click.self="closeSheet" x-on:keydown.escape.window="closeSheet">
            <div class="site-screen__sheet" role="dialog" aria-modal="true">
                <template x-if="isSheet('confirm')">
                    <div>
                        <p x-text="sheetMessage"></p>
                        <div class="site-screen__row"><button type="button" class="btn btn--ghost" x-on:click="answer(false)" x-text="t('cancel')"></button><button type="button" class="btn btn--primary" x-on:click="answer(true)" x-text="t('ok')"></button></div>
                    </div>
                </template>
                <template x-if="isSheet('return')">
                    <div>
                        <h2 class="site-screen__label" x-text="t('return_title')"></h2>
                        <textarea class="site-screen__input" rows="3" maxlength="1000" x-model="drafts.returnComment" x-bind:placeholder="t('return_placeholder')"></textarea>
                        <div class="site-screen__row"><button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('cancel')"></button><button type="button" class="btn btn--primary" x-on:click="confirmReturn" x-text="t('return_send')"></button></div>
                    </div>
                </template>
                <template x-if="isSheet('anomaly')">
                    <div>
                        <h2 class="site-screen__label" x-text="t('reason_title')"></h2>
                        <textarea class="site-screen__input" rows="3" maxlength="1000" x-model="drafts.reason"></textarea>
                        <div class="site-screen__row"><button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('cancel')"></button><button type="button" class="btn btn--primary" x-on:click="confirmAnomaly" x-text="t('ok')"></button></div>
                    </div>
                </template>
                <template x-if="isSheet('exit')">
                    <div>
                        <h2 class="site-screen__label" x-text="t('exit_title')"></h2>
                        <div class="site-screen__statuses">
                            <template x-for="option in statuses" x-bind:key="option">
                                <button type="button" class="site-screen__status" x-bind:class="statusClass(option)" x-on:click="exitWith(option)"><span x-text="statusLabel(option)"></span></button>
                            </template>
                        </div>
                        <button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('cancel')"></button>
                    </div>
                </template>
                @include('audit.partials.report')
                @include('audit.partials.local-photos')
                @include('audit.partials.box-flow-sheets')
                <template x-if="isSheet('navigate')">
                    <div>
                        <h2 class="site-screen__label" x-text="navigateTitle"></h2>
                        <p class="site-screen__muted" x-text="navigateCoordinates"></p>
                        <template x-for="link in navigationLinks" x-bind:key="link.key">
                            <a class="site-screen__shot" x-bind:href="link.url" target="_blank" rel="noopener" x-on:click="rememberNavigation(link.key)" x-text="link.label"></a>
                        </template>
                        <button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('close')"></button>
                    </div>
                </template>
                <template x-if="isSheet('refused')">
                    <div>
                        <h2 class="site-screen__label" x-text="t('refused_title')"></h2>
                        <p class="site-screen__muted" x-text="t('refused_hint')"></p>
                        <template x-for="item in refused" x-bind:key="item.id">
                            <div class="site-screen__refused">
                                <span x-text="refusedText(item)"></span>
                                <span class="site-screen__muted" x-show="isConflict(item)" x-text="conflictMine(item)"></span>
                                <a class="btn btn--ghost" x-show="item.blob" x-bind:href="refusedFileUrl(item)" download="photo.jpg" x-text="t('save_photo')"></a>
                                <button type="button" class="btn btn--primary" x-show="isConflict(item)" x-on:click="keepMine(item)" x-text="t('keep_mine')"></button>
                                <button type="button" class="btn btn--ghost" x-show="isConflict(item)" x-on:click="keepTheirs(item)" x-text="t('keep_theirs')"></button>
                                <button type="button" class="btn btn--ghost" x-show="!isConflict(item)" x-on:click="dismissRefused(item.id)" x-text="t('discard')"></button>
                            </div>
                        </template>
                        <button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('close')"></button>
                    </div>
                </template>
            </div>
        </div>

        <div class="site-screen__overlay site-screen__overlay--zoom" x-show="zoom" x-on:click="closeZoom">
            <img class="site-screen__zoom" x-bind:src="zoomUrl" alt="">
            <div class="box-flow__zoombar" x-show="flowZoomShown" x-on:click.stop>
                <a class="btn btn--ghost" x-show="flowAnnotationsShown" x-bind:href="flowAnnotationsUrl" x-text="flowAnnotationsText"></a>
                <button type="button" class="btn btn--ghost" x-show="flowOffice" x-on:click="flowObservePhoto" x-text="flowObserveText"></button>
            </div>
        </div>
    </div>

    @include('audit.partials.render-failure')

    <div class="toast" data-toast role="status" aria-live="polite"></div>
    @livewireScripts
</body>
</html>
