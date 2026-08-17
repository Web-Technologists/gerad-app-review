<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if(request()->query('host') || request()->query('shop'))
        <meta name="shopify-api-key" content="{{ config('services.shopify.client_id') }}" />
        <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    @endif
    <title>UPI Code Management Center - Shopify Central</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Tailwind CDN for Layout -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        outfit: ['Outfit', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    
    <style>
        /* Modern Shopify Polaris / Glassmorphism inspired aesthetics */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f6f6f7;
            color: #1a1a1a;
        }
        .polaris-card {
            background: #ffffff;
            border: 1px solid #e1e3e5;
            box-shadow: 0px 1px 3px rgba(0, 0, 0, 0.05);
            border-radius: 8px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .polaris-card:hover {
            box-shadow: 0px 4px 12px rgba(0, 0, 0, 0.04);
        }
        .sidebar-item-active {
            background-color: #edeeef;
            color: #1a1a1a;
            font-weight: 500;
        }
        .polaris-btn-primary {
            background: #008060;
            color: #ffffff;
            font-weight: 500;
            border-radius: 6px;
            transition: background 0.2s ease;
        }
        .polaris-btn-primary:hover {
            background: #006e52;
        }
        .badge-active {
            background-color: #e3f1df;
            color: #1e5128;
        }
        .badge-draft {
            background-color: #e4e6e7;
            color: #4a4a4a;
        }
        .badge-archived {
            background-color: #fbeae5;
            color: #8a2b13;
        }
        /* Custom animated loading spinner */
        .spinner {
            border: 2px solid rgba(0, 0, 0, 0.1);
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border-left-color: #008060;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        /* Fade notifications */
        .toast-notify {
            animation: slideIn 0.3s ease forwards;
        }
        @keyframes slideIn {
            from { transform: translateY(100px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    </style>
</head>
<body class="h-full">

<div class="min-h-full flex flex-col">
    <!-- Navbar Header -->
    <header class="bg-white border-b border-gray-200 sticky top-0 z-30">
        <div class="mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <!-- Shopify-like Logo Icon -->
                <div class="bg-[#008060] text-white p-2 rounded-lg shadow-sm">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                    </svg>
                </div>
                <div>
                    <h1 class="text-xl font-bold font-outfit text-gray-900 leading-tight">UPI Code Manager</h1>
                    <p class="text-xs text-gray-500 font-sans">Centralized Store Hub — Shopify Multi-Tenant Architecture</p>
                </div>
            </div>
            
            <div class="flex items-center space-x-4">
                <!-- Connected store indicator count -->
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-[#008060] border border-emerald-200">
                    <span class="h-2 w-2 rounded-full bg-emerald-500 mr-1.5 animate-pulse"></span>
                    {{ $shops->count() }} Stores Connected
                </span>

                <!-- Advanced Developer Toggle Cog -->
                <button onclick="document.getElementById('advanced-drawer').classList.remove('translate-x-full'); document.getElementById('advanced-drawer').classList.remove('hidden');" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-slate-50 rounded-xl transition duration-150 border border-transparent hover:border-slate-100" title="Advanced Developer Utilities">
                    <svg class="w-5.5 h-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Workspace Grid -->
    <div class="flex-grow flex">
        
        <!-- Left Sidebar Options & Onboarding -->
        <aside class="w-72 bg-white border-r border-gray-200 p-6 flex flex-col justify-between hidden md:flex">
            <div class="space-y-6">
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-3">Core Navigation</h3>
                    <nav class="space-y-1">
                        <a href="{{ route('dashboard') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-md sidebar-item-active">
                            <svg class="mr-3 h-5 w-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                            Products Directory
                        </a>
                        <a href="{{ route('dashboard.licensing_export', request()->only(['shop', 'shop_id'])) }}" target="_blank" class="flex items-center px-3 py-2 text-sm font-medium text-emerald-700 rounded-md hover:bg-emerald-50 transition">
                            <svg class="mr-3 h-5 w-5 text-emerald-650" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Licensing Export (All)
                        </a>
                    </nav>
                </div>

                <!-- Recent Jobs Log List -->
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-3">Sync & Job Monitor</h3>
                    <div class="space-y-3" id="jobs-container">
                        @forelse($recentJobs as $job)
                            <div class="p-3 border border-gray-100 rounded-lg bg-gray-50 text-xs space-y-1 relative" id="job-card-{{ $job->id }}">
                                <div class="flex items-center justify-between">
                                    <span class="font-semibold text-gray-700 capitalize">{{ str_replace('_', ' ', $job->type) }}</span>
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-medium 
                                        @if($job->status === 'completed') bg-emerald-50 text-emerald-700
                                        @elseif($job->status === 'processing') bg-blue-50 text-blue-700 animate-pulse
                                        @elseif($job->status === 'failed') bg-rose-50 text-rose-700
                                        @else bg-gray-100 text-gray-700 @endif" id="job-status-badge-{{ $job->id }}">
                                        {{ $job->status }}
                                    </span>
                                </div>
                                <p class="text-[10px] text-gray-500">Rows: <span id="job-processed-{{ $job->id }}">{{ $job->processed_rows }}</span> / <span id="job-total-{{ $job->id }}">{{ $job->total_rows }}</span></p>
                                
                                <div class="job-actions" id="job-action-{{ $job->id }}">
                                    @if($job->status === 'completed' && in_array($job->type, ['csv_export', 'licensing_export']) && $job->file_path)
                                        <a href="{{ route('dashboard.download_export', ['job_id' => $job->id, 'shop_id' => request('shop_id') ?: $job->shop_id, 'shop' => request('shop') ?: ($job->shop ? $job->shop->shop_domain : '')]) }}" target="_blank" class="text-indigo-600 font-semibold hover:underline block mt-1">Download CSV</a>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-gray-400 italic">No sync jobs run recently.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- Footer Meta Info -->
            <div class="border-t border-gray-100 pt-4 text-center">
                <span class="text-[10px] text-gray-400">Laravel v{{ Illuminate\Foundation\Application::VERSION }} (PHP v{{ PHP_VERSION }})</span>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-grow p-6 md:p-8 space-y-6 overflow-y-auto">
            
            <!-- Session Messages Toast -->
            @if(session('success'))
                <div class="bg-emerald-50 border-l-4 border-[#008060] p-4 rounded shadow-sm text-sm text-[#006e52] flex justify-between toast-notify">
                    <span>{{ session('success') }}</span>
                    <button onclick="this.parentElement.remove()" class="font-bold">&times;</button>
                </div>
            @endif

            <!-- Filter Panel -->
            <div class="polaris-card p-6">
                <form action="{{ route('dashboard') }}" method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                    <!-- Search query -->
                    <div class="lg:col-span-1">
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Search Products</label>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Title, UPI, Product ID" class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-1 focus:ring-emerald-500 focus:outline-none">
                    </div>

                    <!-- Store Filter -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Store / Domain</label>
                        <select name="shop_id" class="w-full text-sm border border-gray-300 rounded px-3 py-2 bg-white focus:ring-1 focus:ring-emerald-500 focus:outline-none">
                            <option value="">All Stores</option>
                            @foreach($shops as $shop)
                                <option value="{{ $shop->id }}" {{ request('shop_id') == $shop->id ? 'selected' : '' }}>{{ $shop->shop_name ?: $shop->shop_domain }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Vendor Filter -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Vendor / Brand</label>
                        <select name="vendor" class="w-full text-sm border border-gray-300 rounded px-3 py-2 bg-white focus:ring-1 focus:ring-emerald-500 focus:outline-none">
                            <option value="">All Vendors</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor }}" {{ request('vendor') == $vendor ? 'selected' : '' }}>{{ $vendor }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Product Type Filter -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Product Type</label>
                        <select name="product_type" class="w-full text-sm border border-gray-300 rounded px-3 py-2 bg-white focus:ring-1 focus:ring-emerald-500 focus:outline-none">
                            <option value="">All Types</option>
                            @foreach($productTypes as $type)
                                <option value="{{ $type }}" {{ request('product_type') == $type ? 'selected' : '' }}>{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Status</label>
                        <div class="flex space-x-2">
                            <select name="status" class="w-full text-sm border border-gray-300 rounded px-3 py-2 bg-white focus:ring-1 focus:ring-emerald-500 focus:outline-none">
                                <option value="">All Statuses</option>
                                <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                                <option value="draft" {{ request('status') == 'draft' ? 'selected' : '' }}>Draft</option>
                                <option value="archived" {{ request('status') == 'archived' ? 'selected' : '' }}>Archived</option>
                            </select>
                            
                            <button type="submit" class="px-4 py-2 polaris-btn-primary text-sm font-semibold shadow flex items-center justify-center">
                                Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Products Listing Card -->
            <div class="polaris-card overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50">
                    <h3 class="text-sm font-bold text-gray-700 uppercase tracking-wider">Product Inventory ({{ $products->total() }} results)</h3>
                    @if(request()->anyFilled(['search','shop_id','vendor','product_type','status']))
                        <a href="{{ route('dashboard') }}" class="text-xs text-rose-600 hover:underline font-semibold">Clear Filters</a>
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-gray-400">Store Domain</th>
                                <th scope="col" class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-gray-400">Product Info</th>
                                <th scope="col" class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-gray-400">Vendor / Type</th>
                                <th scope="col" class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-gray-400">Status</th>
                                <th scope="col" class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-gray-400">UPI Code (Universal Identifier)</th>
                                <th scope="col" class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-gray-400">Sync status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100 text-sm">
                            @forelse($products as $p)
                                <tr class="hover:bg-slate-50/70 transition duration-150">
                                    <!-- Store Domain -->
                                    <td class="px-6 py-4 whitespace-nowrap text-xs font-semibold text-gray-600">
                                        {{ $p->shop->shop_name ?: $p->shop->shop_domain }}
                                    </td>
                                    
                                    <!-- Product GID & Title -->
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-gray-900">{{ $p->title }}</div>
                                        <div class="text-[10px] text-gray-400">ID: {{ $p->shopify_product_id }}</div>
                                    </td>

                                    <!-- Vendor / Type -->
                                    <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-600">
                                        <div>{{ $p->vendor }}</div>
                                        <div class="text-[10px] text-gray-400">{{ $p->product_type }}</div>
                                    </td>

                                    <!-- Status -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider
                                            @if($p->status === 'active') badge-active
                                            @elseif($p->status === 'draft') badge-draft
                                            @else badge-archived @endif">
                                            {{ $p->status }}
                                        </span>
                                    </td>

                                    <!-- UPI Code Dynamic Form -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center space-x-2">
                                            <input type="text" value="{{ $p->upi_code }}" 
                                                id="upi-input-{{ $p->id }}"
                                                onblur="saveInlineUpi({{ $p->id }})"
                                                onkeypress="handleKeyPress(event, {{ $p->id }})"
                                                placeholder="e.g. UPI-XYZ-123"
                                                class="border border-gray-300 rounded px-2.5 py-1 text-xs font-medium w-48 focus:border-emerald-500 focus:outline-none transition">
                                            
                                            <!-- Sync indicators -->
                                            <div id="sync-spinner-{{ $p->id }}" class="hidden spinner"></div>
                                            
                                            <div id="sync-success-{{ $p->id }}" class="hidden text-emerald-600">
                                                <svg class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Sync Status Indicator -->
                                    <td class="px-6 py-4 whitespace-nowrap text-xs" id="sync-status-col-{{ $p->id }}">
                                        <span class="inline-flex items-center text-xs font-medium
                                            @if($p->sync_status === 'synced') text-emerald-600
                                            @elseif($p->sync_status === 'pending_push') text-blue-600
                                            @else text-rose-600 @endif">
                                            <span class="h-1.5 w-1.5 rounded-full mr-1.5 
                                                @if($p->sync_status === 'synced') bg-emerald-500
                                                @elseif($p->sync_status === 'pending_push') bg-blue-500 animate-pulse
                                                @else bg-rose-500 @endif"></span>
                                            {{ $p->sync_status }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center text-gray-400 italic">
                                        No products matched the filter query. Try connecting a mock store or clearing search criteria.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Pagination footer -->
                @if($products->hasPages())
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                        {{ $products->links() }}
                    </div>
                @endif
            </div>
        </main>
    </div>
</div>

<!-- ================= MODAL WINDOWS ================= -->

<!-- 1. Connect Mock Store Onboarding Modal -->
<div id="connect-modal" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex items-center justify-center hidden">
    <div class="bg-white rounded-lg max-w-md w-full p-6 shadow-xl border border-gray-200 toast-notify">
        <div class="flex justify-between items-start mb-4">
            <h3 class="text-base font-bold font-outfit text-gray-900">Connect New Store (Demo Simulator)</h3>
            <button onclick="document.getElementById('connect-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 font-bold">&times;</button>
        </div>
        
        <form action="{{ route('shopify.auth') }}" method="GET" class="space-y-4">
            <input type="hidden" name="mock" value="1">
            
            <p class="text-xs text-gray-500 leading-relaxed">
                Enter any store name suffix to simulate a store connecting to this central manager via Shopify OAuth. The app will run the onboarding workflow and populate mock inventory data.
            </p>
            
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase mb-1">Shop Domain Prefix</label>
                <div class="relative rounded-md shadow-sm">
                    <input type="text" name="shop" required placeholder="store-name" class="w-full text-sm border border-gray-300 rounded px-3 py-2 pr-28 focus:outline-none focus:border-emerald-500 font-mono">
                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-gray-400 text-xs font-mono">
                        .myshopify.com
                    </div>
                </div>
            </div>

            <div class="flex justify-end space-x-2 pt-2">
                <button type="button" onclick="document.getElementById('connect-modal').classList.add('hidden')" class="px-4 py-2 border border-gray-200 rounded text-xs font-semibold text-gray-600 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 polaris-btn-primary rounded text-xs font-semibold shadow">Launch Simulator</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Import CSV Modal -->
<div id="import-modal" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex items-center justify-center hidden">
    <div class="bg-white rounded-lg max-w-md w-full p-6 shadow-xl border border-gray-200 toast-notify">
        <div class="flex justify-between items-start mb-4">
            <h3 class="text-base font-bold font-outfit text-gray-900">Bulk Import UPI Codes via CSV</h3>
            <button onclick="document.getElementById('import-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 font-bold">&times;</button>
        </div>
        
        <form action="{{ route('dashboard.import_csv', ['shop_id' => request('shop_id'), 'shop' => request('shop')]) }}" method="POST" enctype="multipart/form-data" class="space-y-4">
            @csrf
            
            <p class="text-xs text-gray-500 leading-relaxed">
                Upload a standard CSV file with column headers <strong class="font-mono text-gray-700">shopify_product_id</strong> and <strong class="font-mono text-gray-700">upi_code</strong>. The app will process updating the product records and syncing them with Shopify in the background.
            </p>

            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase mb-1">Target Shopify Store (Optional)</label>
                <select name="shop_id" class="w-full text-sm border border-gray-300 rounded px-3 py-2 bg-white focus:outline-none">
                    <option value="">Global (Search across all stores)</option>
                    @foreach($shops as $shop)
                        <option value="{{ $shop->id }}">{{ $shop->shop_name ?: $shop->shop_domain }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase mb-1">Select CSV File</label>
                <input type="file" name="csv_file" required accept=".csv,.txt" class="w-full text-sm border border-gray-300 rounded p-2 focus:outline-none">
            </div>

            <div class="flex justify-end space-x-2 pt-2">
                <button type="button" onclick="document.getElementById('import-modal').classList.add('hidden')" class="px-4 py-2 border border-gray-200 rounded text-xs font-semibold text-gray-600 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 polaris-btn-primary rounded text-xs font-semibold shadow">Upload & Process</button>
            </div>
        </form>
    </div>
</div>

<!-- Script Engine -->
<script>
    // AJAX CSRF Token setup
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    /**
     * Submit inline update when pressing Enter
     */
    function handleKeyPress(event, id) {
        if (event.key === 'Enter') {
            event.target.blur(); // Blur triggers onblur saveInlineUpi
        }
    }

    /**
     * Save UPI Code via inline AJAX call
     */
    function saveInlineUpi(id) {
        const input = document.getElementById(`upi-input-${id}`);
        const upiCode = input.value;
        
        const spinner = document.getElementById(`sync-spinner-${id}`);
        const successIcon = document.getElementById(`sync-success-${id}`);
        const syncStatusCol = document.getElementById(`sync-status-col-${id}`);

        // UI Feedback
        spinner.classList.remove('hidden');
        successIcon.classList.add('hidden');

        fetch(`/dashboard/products/${id}/update-upi`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ upi_code: upiCode })
        })
        .then(response => response.json())
        .then(data => {
            spinner.classList.add('hidden');
            if (data.success) {
                // Show inline success micro-animation
                successIcon.classList.remove('hidden');
                
                // Update sync status column dynamically
                syncStatusCol.innerHTML = `
                    <span class="inline-flex items-center text-xs font-medium text-emerald-600">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 mr-1.5"></span>
                        synced
                    </span>
                `;
                
                setTimeout(() => {
                    successIcon.classList.add('hidden');
                }, 2000);
            } else {
                alert('Failed to update UPI code.');
            }
        })
        .catch(error => {
            spinner.classList.add('hidden');
            console.error('Error:', error);
            alert('Error updating UPI code.');
        });
    }

    /**
     * Poll active background jobs to display real-time progress
     */
    function pollActiveJobs() {
        const jobCards = document.querySelectorAll('[id^="job-card-"]');
        jobCards.forEach(card => {
            const jobId = card.id.replace('job-card-', '');
            
            const urlParams = new URLSearchParams(window.location.search);
            const shop = urlParams.get('shop') || '';
            const shopId = urlParams.get('shop_id') || '';
            
            fetch(`/dashboard/job-status/${jobId}?shop=${encodeURIComponent(shop)}&shop_id=${encodeURIComponent(shopId)}`, {
                headers: { 'Accept': 'application/json' }
            })
            .then(res => res.json())
            .then(data => {
                const statusBadge = document.getElementById(`job-status-badge-${jobId}`);
                const processedSpan = document.getElementById(`job-processed-${jobId}`);
                const totalSpan = document.getElementById(`job-total-${jobId}`);
                const actionDiv = document.getElementById(`job-action-${jobId}`);

                // Update counts
                processedSpan.innerText = data.processed_rows;
                totalSpan.innerText = data.total_rows;
                
                // Update status badge
                statusBadge.innerText = data.status;
                statusBadge.className = `px-1.5 py-0.5 rounded text-[10px] font-medium`;
                
                if (data.status === 'completed') {
                    statusBadge.classList.add('bg-emerald-50', 'text-emerald-700');
                    if (['csv_export', 'licensing_export'].includes(data.type) && data.download_url && !actionDiv.querySelector('a')) {
                        actionDiv.innerHTML = `<a href="${data.download_url}" target="_blank" class="text-indigo-600 font-semibold hover:underline block mt-1">Download CSV</a>`;
                    }
                } else if (data.status === 'processing') {
                    statusBadge.classList.add('bg-blue-50', 'text-blue-700', 'animate-pulse');
                } else if (data.status === 'failed') {
                    statusBadge.classList.add('bg-rose-50', 'text-rose-700');
                } else {
                    statusBadge.classList.add('bg-gray-100', 'text-gray-700');
                }
            })
            .catch(err => console.error(err));
        });
    }

    <!-- ================= SLIDE-OVER DEVELOPER PANEL DRAWER ================= -->
    <div id="advanced-drawer" class="fixed inset-0 z-50 overflow-hidden hidden" aria-labelledby="slide-over-title" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" onclick="document.getElementById('advanced-drawer').classList.add('translate-x-full'); setTimeout(() => document.getElementById('advanced-drawer').classList.add('hidden'), 300)"></div>
        <div class="absolute inset-y-0 right-0 max-w-full flex pl-10">
            <div id="drawer-panel" class="w-screen max-w-md bg-slate-900 text-slate-200 shadow-2xl transform transition-transform duration-300 ease-in-out border-l border-slate-800">
                <div class="h-full flex flex-col justify-between p-6">
                    <div class="space-y-6">
                        <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                            <div>
                                <h3 class="text-base font-bold text-white">Advanced Utilities</h3>
                                <p class="text-[9px] text-slate-400 font-sans mt-0.5 tracking-wider uppercase">System Developer Panel</p>
                            </div>
                            <button onclick="document.getElementById('advanced-drawer').classList.add('translate-x-full'); setTimeout(() => document.getElementById('advanced-drawer').classList.add('hidden'), 300)" class="text-slate-400 hover:text-white font-semibold text-lg p-2 rounded-lg hover:bg-slate-800/50">&times;</button>
                        </div>
                        <div class="space-y-5">
                            <!-- CSV Operations -->
                            <div class="bg-slate-850 p-4 rounded-xl border border-slate-800/80 space-y-2">
                                <h4 class="text-[10px] font-bold text-blue-400 uppercase tracking-wider">CSV Data Integration</h4>
                                <p class="text-[9px] text-slate-400">Import bulk UPI codes via file upload, or export products based on filters.</p>
                                <div class="grid grid-cols-2 gap-3 pt-1">
                                    <button onclick="document.getElementById('import-modal').classList.remove('hidden'); document.getElementById('advanced-drawer').classList.add('translate-x-full'); setTimeout(() => document.getElementById('advanced-drawer').classList.add('hidden'), 300);" class="py-2 bg-slate-800 hover:bg-slate-750 text-white rounded-lg text-xs font-semibold transition flex items-center justify-center gap-1 border border-slate-700">
                                        Import CSV
                                    </button>
                                    <a href="{{ route('dashboard.export_csv', request()->all()) }}" target="_blank" class="py-2 bg-slate-800 hover:bg-slate-750 text-white rounded-lg text-xs font-semibold transition flex items-center justify-center gap-1 border border-slate-700 text-center">
                                        Export Filtered
                                    </a>
                                </div>
                            </div>
                            <!-- Install Simulation -->
                            <div class="bg-slate-850 p-4 rounded-xl border border-slate-800/80 space-y-2">
                                <h4 class="text-[10px] font-bold text-emerald-400 uppercase tracking-wider">OAuth Simulator</h4>
                                <p class="text-[9px] text-slate-400">Register and simulate store installation flow locally for development testing.</p>
                                <button onclick="document.getElementById('connect-modal').classList.remove('hidden'); document.getElementById('advanced-drawer').classList.add('translate-x-full'); setTimeout(() => document.getElementById('advanced-drawer').classList.add('hidden'), 300);" class="w-full py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold shadow-md transition flex items-center justify-center gap-1.5 border border-emerald-550">
                                    Simulate Shop Install
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="text-[10px] text-slate-500 text-center border-t border-slate-800 pt-4">
                        Driver Console &bull; Static Layout
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Run poll every 3 seconds for active jobs -->
    <script>
        // Drawer toggle utility
        document.getElementById('advanced-drawer').addEventListener('transitionend', function() {
            if (this.classList.contains('translate-x-full')) {
                this.classList.add('hidden');
            }
        });
    </script>
    <script>
        setInterval(pollActiveJobs, 3000);
    </script>
</body>
</html>
