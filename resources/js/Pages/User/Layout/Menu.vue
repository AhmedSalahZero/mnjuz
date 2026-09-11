<template>
	<div v-if="!getValueByKey('logo') || getValueByKey('logo') === null"
		class="flex items-center justify-between px-5 pt-5 h-20 mb-4">
		<h2 v-if="!menuIconsOnly" class="ml-2 text-2xl">{{ getValueByKey('company_name') }}</h2>
		<div class="flex items-center space-x-2">
			<span v-if="isSidebarOpen === true" @click="closeSidebar()">
				<svg xmlns="http://www.w3.org/2000/svg" width="2em" height="2em" viewBox="0 0 24 24">
					<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2">
						<path d="M5 5L12 5L19 5">
							<animate fill="freeze" attributeName="d" dur="0.4s"
								values="M5 5L12 5L19 5;M5 5L12 12L19 5" />
						</path>
						<path d="M5 12H19">
							<animate fill="freeze" attributeName="d" dur="0.4s" values="M5 12H19;M12 12H12" />
						</path>
						<path d="M5 19L12 19L19 19">
							<animate fill="freeze" attributeName="d" dur="0.4s"
								values="M5 19L12 19L19 19;M5 19L12 12L19 19" />
						</path>
					</g>
				</svg>
			</span>
		</div>
	</div>
	<div v-else class="flex items-center justify-between px-5 pt-5 h-20 mb-1">
		<Link href="/dashboard">
			<img :src="'/media/' + getValueByKey('logo')" :alt="getValueByKey('company_name')"
				class="w-32 object-contain h-full ps-2">
		</Link>
		<div class="flex items-center space-x-2">
			<button @click="toggleMenu">
				<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
					<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
						stroke-width="1.5"
						d="M9 3.5v17M3 9.4c0-2.24 0-3.36.436-4.216a4 4 0 0 1 1.748-1.748C6.04 3 7.16 3 9.4 3h5.2c2.24 0 3.36 0 4.216.436a4 4 0 0 1 1.748 1.748C21 6.04 21 7.16 21 9.4v5.2c0 2.24 0 3.36-.436 4.216a4 4 0 0 1-1.748 1.748C17.96 21 16.84 21 14.6 21H9.4c-2.24 0-3.36 0-4.216-.436a4 4 0 0 1-1.748-1.748C3 17.96 3 16.84 3 14.6z" />
				</svg>
			</button>
			<span v-if="isSidebarOpen === true" @click="closeSidebar()">
				<svg xmlns="http://www.w3.org/2000/svg" width="2em" height="2em" viewBox="0 0 24 24">
					<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2">
						<path d="M5 5L12 5L19 5">
							<animate fill="freeze" attributeName="d" dur="0.4s"
								values="M5 5L12 5L19 5;M5 5L12 12L19 5" />
						</path>
						<path d="M5 12H19">
							<animate fill="freeze" attributeName="d" dur="0.4s" values="M5 12H19;M12 12H12" />
						</path>
						<path d="M5 19L12 19L19 19">
							<animate fill="freeze" attributeName="d" dur="0.4s"
								values="M5 19L12 19L19 19;M5 19L12 12L19 19" />
						</path>
					</g>
				</svg>
			</span>
		</div>
	</div>
	<!-- لون واحد للنص والأيقونات معًا: الأيقونات تستعمل currentColor فترث اللون نفسه -->
	<div class="flex-grow space-y-3 px-2 overflow-y-scroll text-slate-600">
		<div class="flex-1">
			<ul class="pt-2 space-y-1 text-sm mb-2">
				<li v-if="!isOrgAgent" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate"
					:class="$page.url.startsWith('/dashboard') ? 'bg-slate-50 text-black' : ''">
					<Link rel="noopener noreferrer" href="/dashboard"
						class="flex items-center p-2 space-x-3 rounded-md">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><g fill="currentColor"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/></g></svg>
						<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Dashboard') }}</span>
					</Link>
				</li>
				<li class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate"
					:class="$page.url.startsWith('/chats') ? 'bg-slate-50 text-black' : ''">
					<a href="/chats" rel="noopener noreferrer"
						class="flex items-center justify-between p-2 space-x-3 rounded-md no-underline text-inherit"
						@click="(e) => { if (e.ctrlKey || e.metaKey || e.which === 2) return; e.preventDefault(); goToChats(); }">
						<div class="flex items-center space-x-3">
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 3C6.75 3 2.5 6.53 2.5 10.88c0 2.42 1.31 4.58 3.37 6.02c-.08 1.2-.5 2.3-1.2 3.2c-.27.35.01.85.44.78c1.98-.32 3.6-1.14 4.78-2.08c.68.12 1.38.19 2.11.19c5.25 0 9.5-3.53 9.5-7.88S17.25 3 12 3"/></svg>
							<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Chats') }}</span>
						</div>
						<span v-if="parseInt(unreadMessages) > 0"
							class="bg-[#ffe5b4] px-2 text-[11px] rounded-md">{{ unreadMessages }}</span>
					</a>
				</li>
				<li class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate"
					:class="$page.url.startsWith('/contact') ? 'bg-slate-50 text-black' : ''">
					<Link rel="noopener noreferrer" href="/contacts" class="flex items-center p-2 space-x-3 rounded-md">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M9 12a4 4 0 1 0 0-8a4 4 0 0 0 0 8m0 1.6c-3.6 0-6.5 1.9-6.5 4.3v.9c0 .66.54 1.2 1.2 1.2h10.6c.66 0 1.2-.54 1.2-1.2v-.9c0-2.4-2.9-4.3-6.5-4.3M17 11.5a3.25 3.25 0 1 0 0-6.5a3.25 3.25 0 0 0 0 6.5m1.1 1.7h-1.9c1.35 1.04 2.2 2.47 2.2 4.1v.9c0 .28-.05.55-.14.8h3.34c.66 0 1.2-.54 1.2-1.2v-.6c0-2.2-2.1-4-4.7-4"/></svg>
						<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Contacts') }}</span>
					</Link>
				</li>
				<li class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate"
					:class="$page.url.startsWith('/campaign') ? 'bg-slate-50 text-black' : ''">
					<Link rel="noopener noreferrer" href="/campaigns"
						class="flex items-center p-2 space-x-3 rounded-md">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M2.4 11.23L20.1 3.12c.72-.33 1.47.42 1.14 1.14l-8.11 17.7c-.34.74-1.42.64-1.62-.15l-1.6-6.24l-6.24-1.6c-.79-.2-.89-1.28-.15-1.62z"/></svg>
						<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Campaigns') }}</span>
					</Link>
				</li>
				<li class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate"
					:class="$page.url.startsWith('/template') ? 'bg-slate-50 text-black' : ''">
					<Link rel="noopener noreferrer" href="/templates"
						class="flex items-center p-2 space-x-3 rounded-md">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M14 2H7a2.5 2.5 0 0 0-2.5 2.5v15A2.5 2.5 0 0 0 7 22h10a2.5 2.5 0 0 0 2.5-2.5V7.5zm.5 1.9l3.6 3.6h-3.6zM8.5 12h7a.9.9 0 1 1 0 1.8h-7a.9.9 0 1 1 0-1.8m0 4h7a.9.9 0 1 1 0 1.8h-7a.9.9 0 1 1 0-1.8"/></svg>
						<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Message templates') }}</span>
					</Link>
				</li>
				<li v-if="!isOrgAgent" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate"
					:class="$page.url.startsWith('/automation') ? 'bg-slate-50 text-black' : ''">
					<Link rel="noopener noreferrer" href="/automation/basic"
						class="flex items-center p-2 space-x-3 rounded-md">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M13.4 2.2L4.6 13.1c-.4.5-.05 1.25.6 1.25H10l-1.4 7.35c-.15.8.87 1.28 1.38.64l8.8-10.9c.4-.5.05-1.25-.6-1.25H14l1.4-7.35c.15-.8-.87-1.28-1.38-.64z"/></svg>
						<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Automation') }}</span>
					</Link>
				</li>
			</ul>
			<div class="px-4">
				<hr>
			</div>
			<ul class="pb-4 space-y-1 text-sm mt-2">
				<!-- التقارير: زرّ يفتح لوحة بكامل الارتفاع ملاصقة للشريط -->
				<li v-if="isOrgPrivileged" class="rounded-[5px] px-2">
					<button ref="reportsButton" type="button" @click="toggleReports"
						class="w-full flex items-center justify-between p-2 rounded-md hover:bg-slate-50 hover:text-black"
						:class="isReportsActive || reportsOpen ? 'bg-slate-50 text-black' : ''"
						:aria-expanded="reportsOpen" aria-haspopup="true">
						<span class="flex items-center space-x-3 truncate">
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><g fill="currentColor"><rect x="3" y="12" width="4.2" height="9" rx="1.4"/><rect x="9.9" y="6" width="4.2" height="15" rx="1.4"/><rect x="16.8" y="9" width="4.2" height="12" rx="1.4"/></g></svg>
							<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Reports') }}</span>
						</span>
						<svg v-if="!menuIconsOnly" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
							fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
							class="shrink-0 transition-transform" :class="isRtl ? 'rotate-180' : ''">
							<polyline points="9 18 15 12 9 6" />
						</svg>
					</button>
				</li>
				<li v-if="!isOrgAgent" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate"
					:class="$page.url.startsWith('/team') ? 'bg-slate-50 text-black' : ''">
					<Link rel="noopener noreferrer" href="/team" class="flex items-center p-2 space-x-3 rounded-md">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M8 12a3.5 3.5 0 1 0 0-7a3.5 3.5 0 0 0 0 7m0 1.5c-3.2 0-5.8 1.7-5.8 3.9v1.1c0 .55.45 1 1 1h9.6c.55 0 1-.45 1-1v-1.1c0-2.2-2.6-3.9-5.8-3.9m9.5-2a3 3 0 1 0 0-6a3 3 0 0 0 0 6m.6 1.6h-1.5c1.15.95 1.85 2.2 1.85 3.6v1.1c0 .28-.05.55-.14.8H21c.55 0 1-.45 1-1v-.7c0-2-1.75-3.6-3.9-3.8"/></svg>
						<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Team') }}</span>
					</Link>
				</li>
				<li v-if="!isOrgAgent" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate"
					:class="$page.url.startsWith('/settings') ? 'bg-slate-50 text-black' : ''">
					<Link rel="noopener noreferrer" href="/settings"
						class="md:flex items-center p-2 space-x-3 rounded-md hidden">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M19.14 12.94c.04-.31.06-.62.06-.94s-.02-.63-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96a7 7 0 0 0-1.62-.94l-.36-2.54a.48.48 0 0 0-.48-.41h-3.84a.48.48 0 0 0-.48.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.48.48 0 0 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.31-.09.63-.09.94s.02.63.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.25.41.48.41h3.84c.24 0 .44-.17.48-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32a.49.49 0 0 0-.12-.61zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2"/></svg>
						<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Settings') }}</span>
					</Link>
					<Link rel="noopener noreferrer" href="/settings/m"
						class="flex items-center p-2 space-x-3 rounded-md md:hidden">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M19.14 12.94c.04-.31.06-.62.06-.94s-.02-.63-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96a7 7 0 0 0-1.62-.94l-.36-2.54a.48.48 0 0 0-.48-.41h-3.84a.48.48 0 0 0-.48.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.48.48 0 0 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.31-.09.63-.09.94s.02.63.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.25.41.48.41h3.84c.24 0 .44-.17.48-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32a.49.49 0 0 0-.12-.61zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2"/></svg>
						<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Settings') }}</span>
					</Link>
				</li>
				<li v-if="isOrgAgent" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate"
					:class="$page.url.startsWith('/settings/devices') || $page.url.startsWith('/settings/device') ? 'bg-slate-50 text-black' : ''">
					<Link rel="noopener noreferrer" href="/settings/devices"
						class="flex items-center p-2 space-x-3 rounded-md">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M15.5 2h-7A2.5 2.5 0 0 0 6 4.5v15A2.5 2.5 0 0 0 8.5 22h7a2.5 2.5 0 0 0 2.5-2.5v-15A2.5 2.5 0 0 0 15.5 2M12 20.4a1.1 1.1 0 1 1 0-2.2a1.1 1.1 0 0 1 0 2.2M16.2 5H7.8v11.2h8.4z"/></svg>
						<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Linked Devices') }}</span>
					</Link>
				</li>
				<li v-if="isOrgAgent && organization?.plan?.features?.shortcuts" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate"
					:class="$page.url.startsWith('/settings/shortcuts') ? 'bg-slate-50 text-black' : ''">
					<Link rel="noopener noreferrer" href="/settings/shortcuts"
						class="flex items-center p-2 space-x-3 rounded-md">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2m2 3v2h2V8zm4 0v2h2V8zm4 0v2h2V8zm4 0v2h2V8zM6 12v2h2v-2zm4 0v2h8v-2zm-4 4v2h12v-2z"/></svg>
						<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Shortcuts') }}</span>
					</Link>
				</li>
				<li v-if="!isOrgAgent" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate"
					:class="$page.url.startsWith('/billing') || $page.url.startsWith('/subscription') ? 'bg-slate-50 text-black' : ''">
					<Link rel="noopener noreferrer" href="/billing" class="flex items-center p-2 space-x-3 rounded-md">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M20 4H4a2.5 2.5 0 0 0-2.5 2.5V8h21V6.5A2.5 2.5 0 0 0 20 4M1.5 10v7.5A2.5 2.5 0 0 0 4 20h16a2.5 2.5 0 0 0 2.5-2.5V10zM5 15.5h4a.9.9 0 1 1 0 1.8H5a.9.9 0 1 1 0-1.8"/></svg>
						<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Billing and subscription') }}</span>
						</Link>
					</li>
					
				<li class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate"
					:class="$page.url.startsWith('/support') && !$page.url.startsWith('/support/meetings') ? 'bg-slate-50 text-black' : ''">
					<Link rel="noopener noreferrer" href="/support" class="flex items-center p-2 space-x-3 rounded-md">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20a10 10 0 0 0 0-20m0 3.4c1.28 0 2.47.36 3.48.98l-2.2 2.2a3.4 3.4 0 0 0-2.56 0l-2.2-2.2A6.6 6.6 0 0 1 12 5.4M5.4 12c0-1.28.36-2.47.98-3.48l2.2 2.2a3.4 3.4 0 0 0 0 2.56l-2.2 2.2A6.6 6.6 0 0 1 5.4 12m6.6 6.6a6.6 6.6 0 0 1-3.48-.98l2.2-2.2a3.4 3.4 0 0 0 2.56 0l2.2 2.2c-1.01.62-2.2.98-3.48.98m3.62-4.32a3.4 3.4 0 0 0 0-2.56l2.2-2.2a6.57 6.57 0 0 1 0 6.96zM12 14a2 2 0 1 1 0-4a2 2 0 0 1 0 4"/></svg>
						<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Support') }}</span>
						</Link>
					</li>
					<!-- حجز موعد مع الدعم — تحت الدعم مباشرة. متاح للموظف أيضاً لأن
						 middleware يسمح بكل ما تحت مسار support. -->
					<li class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate"
						:class="$page.url.startsWith('/support/meetings') ? 'bg-slate-50 text-black' : ''">
						<Link rel="noopener noreferrer" href="/support/meetings" class="flex items-center p-2 space-x-3 rounded-md">
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a2.5 2.5 0 0 1 2.5 2.5V9h-19V6.5A2.5 2.5 0 0 1 5 4h1V3a1 1 0 0 1 1-1M2.5 11v8.5A2.5 2.5 0 0 0 5 22h14a2.5 2.5 0 0 0 2.5-2.5V11zm5 3h3v3h-3z"/></svg>
							<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Book a meeting') }}</span>
					</Link>
				</li>
				<li v-if="isOrgPrivileged"
					class="hover:bg-slate-50 hover:text-black rounded-[5px] px-2 truncate">
					<Link rel="noopener noreferrer" href="/developer-tools/access-tokens"
						class="flex items-center p-2 space-x-3 rounded-md">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M5 2h14a3 3 0 0 1 3 3v14a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V5a3 3 0 0 1 3-3m1.9 5.3a1 1 0 0 0 0 1.4L9.1 11l-2.2 2.3a1 1 0 1 0 1.4 1.4l3-3a1 1 0 0 0 0-1.4l-3-3a1 1 0 0 0-1.4 0M12.5 16a1 1 0 1 0 0 2h4.6a1 1 0 1 0 0-2z"/></svg>
						<span :class="menuIconsOnly ? 'hidden' : ''">{{ $t('Developer Tools') }}</span>
					</Link>
				</li>
			</ul>
		</div>
	</div>
	<div v-if="menuIconsOnly === false" @click="switchTeams()"
		class="border-2 border-primary text-sm rounded-[5px] mb-1 m-3 py-2 px-4 flex items-center justify-between cursor-pointer">
		<div class="flex space-x-1">
			<span>{{ $t('Team') }}:</span>
			<span class="text-ellipsis w-[120px] truncate">{{ props.organization.name }}</span>
		</div>
		<span>
			<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24">
				<path fill="currentColor"
					d="M19.696 8.72a1.22 1.22 0 0 1-.3.64l-6.09 6.76a1.85 1.85 0 0 1-.58.46a1.7 1.7 0 0 1-1.42.03a1.75 1.75 0 0 1-.62-.42l-6.1-6.83a1.28 1.28 0 0 1-.31-.64a1.31 1.31 0 0 1 .56-1.26a1.36 1.36 0 0 1 .68-.21h13a1.293 1.293 0 0 1 1.15.76c.081.228.092.476.03.71">
				</path>
			</svg>
		</span>
	</div>
	<div class="flex items-center m-3 p-2 rounded-xl h-14 py-1 md:py-1 mt-2 gap-x-4 bg-slate-50"
		:class="!menuIconsOnly ? 'justify-between' : 'justify-center'">
		<div v-if="!menuIconsOnly" class="flex space-x-2">
			<div class="rounded-xl p-1 bg-slate-200">
				<img v-if="user.avatar" class="rounded-full w-9 h-9" :src="'/media/' + user.avatar">
				<div v-else class="rounded-full w-9 h-9 flex justify-center items-center">
					<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
						<g fill="none" stroke="currentColor" stroke-width="1.5">
							<circle cx="12" cy="6" r="4" />
							<path stroke-linecap="round"
								d="M19.998 18c.002-.164.002-.331.002-.5c0-2.485-3.582-4.5-8-4.5s-8 2.015-8 4.5S4 22 12 22c2.231 0 3.84-.157 5-.437" />
						</g>
					</svg>
				</div>
			</div>
			<div>
				<h2 class="text-[15px] capitalize truncate w-[6em]" :class="menuIconsOnly ? 'hidden' : ''">
					{{ user.first_name + ' ' + user.last_name }}
				</h2>
				<span class="flex items-center space-x-1" :class="menuIconsOnly ? 'hidden' : ''">
					<span @click="openModal"
						class="text-sm hover:underline dark:text-gray-400 cursor-pointer">{{ $t('View profile') }}</span>
				</span>
			</div>
		</div>
		<Link href="/logout" class="hover:bg-[#F6F7F9] hover:rounded-full w-[fit-content] p-2">
			<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
				<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
					d="m12 15l3-3m0 0l-3-3m3 3H4m5-4.751V7.2c0-1.12 0-1.68.218-2.108c.192-.377.497-.682.874-.874C10.52 4 11.08 4 12.2 4h4.6c1.12 0 1.68 0 2.107.218c.377.192.683.497.875.874c.218.427.218.987.218 2.105v9.607c0 1.118 0 1.677-.218 2.104a2.002 2.002 0 0 1-.875.874c-.427.218-.986.218-2.104.218h-4.606c-1.118 0-1.678 0-2.105-.218a2 2 0 0 1-.874-.874C9 18.48 9 17.92 9 16.8v-.05" />
			</svg>
		</Link>
	</div>
	<Modal :label="$t('Switch teams')" :isOpen="isLocationSwitchModalOpen">
		<div class="mt-2 grid grid-cols-1 gap-x-6">
			<div class="pt-3 space-y-2 text-sm">
				<div v-for="(item, index) in props.organizations" :key="index"
					@click="selectOrganization(item.organization.uuid)"
					class="flex gap-x-8 hover:bg-slate-200 rounded-lg py-1 justify-between items-center w-full cursor-pointer border border-slate-100 pl-1 pr-2">
					<div class="flex items-center gap-x-2">
						<span class="bg-slate-200 w-10 h-10 rounded-lg flex items-center justify-center">
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
								<g fill="none" fill-rule="evenodd">
									<path
										d="M24 0v24H0V0h24ZM12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035c-.01-.004-.019-.001-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427c-.002-.01-.009-.017-.017-.018Zm.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093c.012.004.023 0 .029-.008l.004-.014l-.034-.614c-.003-.012-.01-.02-.02-.022Zm-.715.002a.023.023 0 0 0-.027.006l-.006.014l-.034.614c0 .012.007.02.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01l-.184-.092Z" />
									<path fill="currentColor"
										d="M17 3.722v5.497l2.864.716A1.5 1.5 0 0 1 21 11.39V19a1 1 0 1 1 0 2H3a1 1 0 1 1 0-2v-7.69a1.5 1.5 0 0 1 .83-1.343L7 8.382V6.347a1.5 1.5 0 0 1 .973-1.405l7-2.625A1.5 1.5 0 0 1 17 3.722Zm-2 .721l-6 2.25V19h6V4.443Zm2 6.838V19h2v-7.22l-2-.5Zm-10-.663l-2 1V19h2v-8.382Z" />
								</g>
							</svg>
						</span>
						<div>
							<h3>{{ item.organization.name }}</h3>
						</div>
					</div>
					<span>
						<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 20 20">
							<path fill="currentColor" fill-rule="evenodd"
								d="M5 2.643v14.765c.092.32.299.511.619.572c.32.061.633-.024.94-.255l8.107-6.993A.944.944 0 0 0 15 10a.94.94 0 0 0-.334-.73L6.58 2.295c-.232-.197-.639-.383-1.061-.253c-.282.087-.455.287-.519.6" />
						</svg>
					</span>
				</div>
				<div v-if="!isOrgAgent" @click="isOpenOrganizationModal = true"
					class="flex gap-x-8 bg-slate-50 hover:bg-slate-200 rounded-lg py-1 justify-between items-center w-full cursor-pointer border border-slate-100 pl-1 pr-2 py-3">
					<div class="w-full">
						<h3 class="text-center">{{ $t('Create organization') }}</h3>
					</div>
				</div>
			</div>
			<div class="mt-4 border-t pt-4">
				<button type="button" @click.self="isLocationSwitchModalOpen = false"
					class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
			</div>
		</div>
	</Modal>
	<ProfileModal :user="props.user" :organization="props.organization" :isOpen="isOpen" role="user"
		:languages="languages" @close="closeModal()" />
	<OrganizationModal v-model:modelValue="isOpenOrganizationModal" />
	<!--
		لوحة التقارير: fixed لا absolute، لأن حاوية القائمة عليها overflow-y-scroll
		وهي تقصّ كل ما يخرج عنها أفقياً — فكانت اللوحة تُرسم ثم تُقصّ فتبدو مختفية.
		وبكامل الارتفاع ملاصقةً للشريط كما في التصميم المرجعي.
	-->
	<teleport to="body">
		<div v-if="reportsOpen" class="fixed inset-0 z-[60]" @click="reportsOpen = false"></div>
		<transition
			enter-active-class="transition ease-out duration-200"
			:enter-from-class="isRtl ? 'translate-x-full opacity-0' : '-translate-x-full opacity-0'"
			enter-to-class="translate-x-0 opacity-100"
			leave-active-class="transition ease-in duration-150"
			leave-from-class="translate-x-0 opacity-100"
			:leave-to-class="isRtl ? 'translate-x-full opacity-0' : '-translate-x-full opacity-0'">
			<aside v-if="reportsOpen" :style="reportsPanelStyle"
				class="fixed inset-y-0 z-[61] flex flex-col bg-white shadow-2xl border-slate-200"
				:class="isRtl ? 'border-l' : 'border-r'">
				<div class="flex items-center justify-between px-5 h-20 shrink-0">
					<h2 class="text-lg font-semibold text-gray-900">{{ $t('Reports') }}</h2>
					<button type="button" @click="reportsOpen = false" :aria-label="$t('Close')"
						class="rounded-md p-1 text-slate-500 hover:bg-slate-100 hover:text-black">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
							stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
						</svg>
					</button>
				</div>
				<nav class="flex-grow overflow-y-auto px-3 pb-4 space-y-1 text-sm">
					<Link v-if="organization?.plan?.features?.agent_performance" href="/performance"
						class="block rounded-[5px] px-3 py-3 hover:bg-slate-50 hover:text-black"
						:class="$page.url.startsWith('/performance') ? 'bg-slate-50 text-black' : ''"
						@click="reportsOpen = false">
						{{ $t('Agent Performance') }}
					</Link>
					<Link v-if="organization?.plan?.features?.activity_log" href="/activity-log"
						class="block rounded-[5px] px-3 py-3 hover:bg-slate-50 hover:text-black"
						:class="$page.url.startsWith('/activity-log') ? 'bg-slate-50 text-black' : ''"
						@click="reportsOpen = false">
						{{ $t('Activity Log') }}
					</Link>
					<Link v-if="isOrgPrivileged" href="/ratings"
						class="block rounded-[5px] px-3 py-3 hover:bg-slate-50 hover:text-black"
						:class="$page.url.startsWith('/ratings') ? 'bg-slate-50 text-black' : ''"
						@click="reportsOpen = false">
						{{ $t('Customer Ratings') }}
					</Link>
				</nav>
			</aside>
		</transition>
	</teleport>
</template>
<script setup>
import axios from "axios"
import { Link, router, useForm, usePage } from "@inertiajs/vue3"
import { defineProps, ref, computed, onMounted, onUnmounted, nextTick } from "vue"
import FormInput from '@/Components/FormInput.vue'
import Modal from '@/Components/Modal.vue'
import ProfileModal from '@/Components/ProfileModal.vue'
import OrganizationModal from '@/Components/OrganizationModal.vue'

const props = defineProps(['config', 'user', 'organization', 'organizations', 'isSidebarOpen', 'unreadMessages'])

const orgRole = computed(() => usePage().props.auth?.user?.organization_team_role ?? props.user?.teams?.[0]?.role ?? '')
const isOrgAgent = computed(() => orgRole.value === 'agent')
const isOrgPrivileged = computed(() => ['owner', 'manager'].includes(orgRole.value))

const languages = computed(() => usePage().props.languages)
const currentLanguage = computed(() => usePage().props.currentLanguage)
const isOpen = ref(false)
const isLocationSwitchModalOpen = ref(false)
const showDropdown1 = ref(false)
const isOpenOrganizationModal = ref(false)
const menuIconsOnly = ref(localStorage.getItem('MenuOpen') === 'true' ?? false)

// لوحة «التقارير». تُفتح بالنقر فقط: لوحة بكامل الارتفاع تُفتح بمرور المؤشّر مزعجة.
const reportsOpen = ref(false)
const reportsButton = ref(null)
const reportsPanelStyle = ref({})
const isRtl = computed(() => usePage().props.isRtl)
const isReportsActive = computed(() =>
	usePage().url.startsWith('/performance') || usePage().url.startsWith('/activity-log') || usePage().url.startsWith('/ratings')
)

// لا نُظهر تبويب «التقارير» إن لم تكن أي ميزة تحته مفعّلة في الباقة،
// فتبويبٌ يفتح لوحةً فارغة أسوأ من غيابه.
// لا شرط باقة على زرّ «التقارير»: «تقييمات العملاء» متاحة لكل الباقات (المقيَّد
// بالباقة هو حذف التقييمات لا عرضها)، فاللوحة تحمل عنصراً واحداً على الأقلّ لكل
// مخوَّل. الشرط القديم كان يفحص agent_performance وactivity_log وحدهما، فمنشأة
// بلا هاتين الميزتين كان يختفي عنها الزرّ كلّه ومعه رابطٌ لا علاقة له بباقتها.

const REPORTS_PANEL_WIDTH = 288 // 18rem

/**
 * نحسب موضع اللوحة من الحجم الفعلي للشريط لا من قيمة ثابتة، فعرضه يتبدّل بين
 * w-80 و w-20 حسب وضع الأيقونات، ويختلف جانبه بين العربية والإنجليزية.
 */
const positionReportsPanel = () => {
	const host = reportsButton.value?.closest('aside')
	const isMobile = window.innerWidth < 768

	// في الجوال الشريط درج بعرض الشاشة، فاللوحة تغطّيه بالكامل.
	if (!host || isMobile) {
		reportsPanelStyle.value = { left: '0px', right: '0px' }
		return
	}

	const rect = host.getBoundingClientRect()
	reportsPanelStyle.value = isRtl.value
		? { right: `${Math.round(window.innerWidth - rect.left)}px`, width: `${REPORTS_PANEL_WIDTH}px` }
		: { left: `${Math.round(rect.right)}px`, width: `${REPORTS_PANEL_WIDTH}px` }
}

const toggleReports = () => {
	reportsOpen.value = !reportsOpen.value
	if (reportsOpen.value) nextTick(positionReportsPanel)
}

const onReportsKeydown = (e) => {
	if (e.key === 'Escape') reportsOpen.value = false
}

onMounted(() => {
	window.addEventListener('resize', positionReportsPanel)
	window.addEventListener('keydown', onReportsKeydown)
})

onUnmounted(() => {
	window.removeEventListener('resize', positionReportsPanel)
	window.removeEventListener('keydown', onReportsKeydown)
})

const emit = defineEmits(['closeSidebar'])

const form = useForm({
	uuid: null,
})

const getValueByKey = (key) => {
	const found = props.config.find(item => item.key === key)
	return found ? found.value : ''
}

const goToChats = () => {
	const page = usePage()
	// داخل قسم المحادثات (قائمة أو محادثة): لا نطلب rows ولا ننفّذ الاستعلام في Laravel
	const isAlreadyInChats = page.url.startsWith('/chats')
	router.visit('/chats', {
		only: isAlreadyInChats
			? ['flash']
			: ['rows', 'rowCount', 'filters', 'status', 'chat_sort_direction', 'flash'],
		preserveState: true,
		preserveScroll: true,
	})
}

const closeSidebar = () => {
	emit('closeSidebar', true)
}

const toggleMenu = () => {
	menuIconsOnly.value = !menuIconsOnly.value
	localStorage.setItem('MenuOpen', menuIconsOnly.value)
}

defineExpose({
	menuIconsOnly
})

const toggleDropdown = (type) => {
	if (type === 'dropdown1') {
		showDropdown1.value = !showDropdown1.value
	}
}

const closeModal = () => {
	isOpen.value = false
}

const openModal = () => {
	isOpen.value = true
	emit('closeSidebar', true)
}

const switchTeams = () => {
	isLocationSwitchModalOpen.value = true
	emit('closeSidebar', true)
}

const selectOrganization = (uuid) => {
	form.uuid = uuid
	submitForm()
}

const submitForm = async () => {
	form.post('/select-organization', {
		preserveScroll: true,
		onFinish: isLocationSwitchModalOpen.value = false,
	})
}

// Check for language refresh parameter and refresh page if needed
onMounted(() => {
	const urlParams = new URLSearchParams(window.location.search)
	if (urlParams.get('refresh_lang') === '1') {
		// Remove the parameter and refresh to apply user's language
		urlParams.delete('refresh_lang')
		const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '')
		window.history.replaceState({}, '', newUrl)
		window.location.reload()
	}
})
</script>
