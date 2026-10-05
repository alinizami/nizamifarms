@extends('layouts.app')

@section('title', 'Attendance Management')

@section('content')
<script>
  // Pass logged-in user info to JavaScript
  window.currentUser = {
    id: {{ auth()->id() }},
    name: "{{ auth()->user()->fullname ?? 'User' }}"
  };
</script>
<div class="max-w-7xl mx-auto p-6">
  <!-- Header with Add Button -->
  <div class="flex justify-between items-center mb-6 relative" style="z-index: 10;">
    <h1 class="text-2xl font-semibold text-gray-900">Attendance Management</h1>
    <div class="flex gap-2 items-center">
      <button
        type="button"
        onclick="toggleAddForm()"
        id="toggleFormBtn"
        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold shadow-md"
        style="position: relative; z-index: 20; pointer-events: auto; cursor: pointer;"
      >
        ➕ Mark Attendance
      </button>
      <button
        type="button"
        onclick="openApplyLeave()"
        style="background:#16a34a;color:#ffffff;"
        class="px-4 py-2 rounded-lg transition font-semibold shadow-md text-center hover:opacity-90"
      >
        📝 Apply Leave
      </button>
      <!-- Settings Dropdown -->
      <div class="relative inline-block" id="settingsDropdown">
        <button 
          type="button"
          onclick="toggleSettingsMenu(); return false;" 
          class="px-4 py-2 bg-white text-gray-800 rounded-lg hover:bg-gray-100 transition font-semibold shadow-md border-2 border-gray-300 inline-flex items-center gap-2"
        >
          ⚙️ Settings
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
          </svg>
        </button>
        <!-- Dropdown Menu -->
        <div id="settingsMenu" class="hidden absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-200 z-50">
          <div class="py-2">
            <a
              href="/attendance/reports"
              class="block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              📊 Full Reports (print)
            </a>
            <a
              href="/riders"
              class="block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              👤 Rider Profiles
            </a>
            <a
              href="/attendance/locations"
              class="block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              📍 Office Locations
            </a>
            <a
              href="/shift-planner"
              class="block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              📅 Shift Planner
            </a>
            <a
              href="/shifts"
              class="block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              🗂️ Shift Types
            </a>
            <a
              href="/holidays"
              class="block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              🎉 Public Holidays
            </a>
            <button 
              type="button"
              onclick="openCustomizeUserList(); toggleSettingsMenu();" 
              class="w-full text-left block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              👥 Customize User List
            </button>
            <button
              type="button"
              onclick="openFuelRateModal(); toggleSettingsMenu();"
              class="w-full text-left block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              ⛽ Fuel Rate Groups
            </button>
            <button
              type="button"
              onclick="openAttendanceRules(); toggleSettingsMenu();"
              class="w-full text-left block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              📐 Attendance Rules
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Date breakdown modal (exact dates behind a Month-tab count) -->
  <div id="dateBreakdownModal" style="display:none;position:fixed;inset:0;z-index:10001;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)closeDateBreakdown()">
    <div style="background:#ffffff;border-radius:12px;box-shadow:0 20px 40px rgba(0,0,0,0.25);max-width:420px;width:100%;padding:20px 22px;max-height:80vh;display:flex;flex-direction:column;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px;">
        <div>
          <h2 id="bdTitle" style="font-size:16px;font-weight:700;color:#111827;margin:0;">Dates</h2>
          <div id="bdSub" style="font-size:12px;color:#6B7280;margin-top:2px;"></div>
        </div>
        <button type="button" onclick="closeDateBreakdown()" style="background:none;border:none;font-size:24px;line-height:1;color:#9ca3af;cursor:pointer;">&times;</button>
      </div>
      <div id="bdBody" style="overflow:auto;margin-top:8px;"></div>
    </div>
  </div>

  <!-- Payroll leave actions (overtime bonus / late penalty) — the SAME items the Payroll
       screen shows, decided from here so a manager reviewing the month doesn't have to
       switch screens. Every call is gated by `manage_payroll` server-side. -->
  {{-- z-index sits BELOW the date-breakdown modal (10001) on purpose: "see the days" opens
       that one on top, and closing it drops the manager back onto this list. --}}
  <div id="leaveActModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)closePayrollLeaveActions()">
    <div style="background:#ffffff;border-radius:12px;box-shadow:0 20px 40px rgba(0,0,0,0.25);max-width:560px;width:100%;padding:20px 22px;max-height:85vh;display:flex;flex-direction:column;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px;">
        <div>
          <h2 id="laTitle" style="font-size:16px;font-weight:700;color:#111827;margin:0;">Leave actions</h2>
          <div id="laSub" style="font-size:12px;color:#6B7280;margin-top:2px;"></div>
        </div>
        <button type="button" onclick="closePayrollLeaveActions()" style="background:none;border:none;font-size:24px;line-height:1;color:#9ca3af;cursor:pointer;">&times;</button>
      </div>
      <div id="laBody" style="overflow:auto;margin-top:10px;"></div>
    </div>
  </div>

  <!-- Attendance Rules Modal (year cycle + meter thresholds) -->
  <div id="attRulesModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)closeAttendanceRules()">
    <div style="background:#ffffff;border-radius:12px;box-shadow:0 20px 40px rgba(0,0,0,0.25);max-width:520px;width:100%;padding:24px;max-height:90vh;overflow:auto;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
        <h2 style="font-size:18px;font-weight:700;color:#111827;margin:0;">📐 Attendance Rules</h2>
        <button type="button" onclick="closeAttendanceRules()" style="background:none;border:none;font-size:26px;line-height:1;color:#9ca3af;cursor:pointer;">&times;</button>
      </div>
      <p style="font-size:12.5px;color:#6b7280;margin:0 0 14px;">Open a section to edit it. These settings drive the counters, warnings, leave and overtime — they do not change salary.</p>

      <!-- Year cycle -->
      <div style="border:1px solid #e5e7eb;border-radius:10px;margin-bottom:10px;overflow:hidden;">
        <button type="button" onclick="toggleRuleSection('secCycle')" style="width:100%;display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:none;padding:12px 14px;cursor:pointer;">
          <span style="font-weight:700;color:#111827;font-size:14px;">📅 Yearly cycle</span>
          <span id="secCycleChev" style="color:#9ca3af;font-size:13px;">▾</span>
        </button>
        <div id="secCycle" style="display:block;padding:14px;border-top:1px solid #f1f5f9;">
          <div style="font-size:12px;color:#6b7280;margin-bottom:12px;">The 12-month window for "leaves this year" and "absent this year". Not fixed to January — set it to your cycle (e.g. June → May).</div>
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:150px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Cycle start</label>
              <input type="date" id="ruleCycleStart" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
            </div>
            <div style="flex:1;min-width:150px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Cycle end</label>
              <input type="date" id="ruleCycleEnd" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
            </div>
          </div>
          <div id="ruleCycleHint" style="font-size:11.5px;color:#2563eb;margin-top:8px;"></div>
        </div>
      </div>

      <!-- Meter checks -->
      <div style="border:1px solid #e5e7eb;border-radius:10px;margin-bottom:10px;overflow:hidden;">
        <button type="button" onclick="toggleRuleSection('secMeter')" style="width:100%;display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:none;padding:12px 14px;cursor:pointer;">
          <span style="font-weight:700;color:#111827;font-size:14px;">🏍 Meter checks</span>
          <span id="secMeterChev" style="color:#9ca3af;font-size:13px;">▸</span>
        </button>
        <div id="secMeter" style="display:none;padding:14px;border-top:1px solid #f1f5f9;">
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:150px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">GPS mismatch warn (km)</label>
              <input type="number" min="0" step="1" id="ruleGpsWarn" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Warn when meter distance differs from road/GPS by more than this.</div>
            </div>
            <div style="flex:1;min-width:150px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Overnight bike grace (km)</label>
              <input type="number" min="0" step="1" id="ruleOvernight" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Default for company-bike riders. Can be overridden per rider on the Riders page.</div>
            </div>
            <div style="flex:1;min-width:150px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Petrol request window (days)</label>
              <input type="number" min="1" step="1" id="rulePetrolWindow" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">How many days back a rider may raise a meter petrol request from their attendance.</div>
            </div>
            <div style="flex:1;min-width:150px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Manager on-behalf window (days)</label>
              <input type="number" min="1" step="1" id="rulePetrolWindowMgr" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">How many days back a MANAGER may raise one for a rider. Repairs are found late, so this is deliberately longer; it can never be less than the rider window above.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Leave policy -->
      <div style="border:1px solid #e5e7eb;border-radius:10px;margin-bottom:10px;overflow:hidden;">
        <button type="button" onclick="toggleRuleSection('secLeave')" style="width:100%;display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:none;padding:12px 14px;cursor:pointer;">
          <span style="font-weight:700;color:#111827;font-size:14px;">🏖 Leave policy</span>
          <span id="secLeaveChev" style="color:#9ca3af;font-size:13px;">▸</span>
        </button>
        <div id="secLeave" style="display:none;padding:14px;border-top:1px solid #f1f5f9;">
          <div style="font-size:12px;color:#6b7280;margin-bottom:12px;">One yearly pool of leaves. A few of them may be applied the same day (emergency) up to a cap, before a cutoff time.</div>
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:130px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Leaves per year</label>
              <input type="number" min="0" step="1" id="ruleLeaveTotal" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
            </div>
            <div style="flex:1;min-width:130px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Same-day allowed</label>
              <input type="number" min="0" step="1" id="ruleSamedayCap" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">How many can be taken same-day (emergency).</div>
            </div>
            <div style="flex:1;min-width:130px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Same-day cutoff</label>
              <input type="time" id="ruleSamedayCutoff" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Same-day leave must be applied before this time.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Overtime -->
      <div style="border:1px solid #e5e7eb;border-radius:10px;margin-bottom:10px;overflow:hidden;">
        <button type="button" onclick="toggleRuleSection('secOvertime')" style="width:100%;display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:none;padding:12px 14px;cursor:pointer;">
          <span style="font-weight:700;color:#111827;font-size:14px;">⏱ Overtime</span>
          <span id="secOvertimeChev" style="color:#9ca3af;font-size:13px;">▸</span>
        </button>
        <div id="secOvertime" style="display:none;padding:14px;border-top:1px solid #f1f5f9;">
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:150px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Shift length (hours)</label>
              <input type="number" min="0" step="0.5" id="ruleTargetHours" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Checking out after this many hours starts counting overtime.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Check-in rule -->
      <div style="border:1px solid #e5e7eb;border-radius:10px;margin-bottom:16px;overflow:hidden;">
        <button type="button" onclick="toggleRuleSection('secCheckin')" style="width:100%;display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:none;padding:12px 14px;cursor:pointer;">
          <span style="font-weight:700;color:#111827;font-size:14px;">📍 Check-in rule</span>
          <span id="secCheckinChev" style="color:#9ca3af;font-size:13px;">▸</span>
        </button>
        <div id="secCheckin" style="display:none;padding:14px;border-top:1px solid #f1f5f9;">
          <div style="font-size:12px;color:#6b7280;margin-bottom:12px;">When on, a rider can only mark attendance from the app while their GPS is inside their shift location's radius (set per location on the Office Locations page). Too far — or no GPS — and check-in is blocked. You can still mark someone present yourself from Mark Attendance. Off = check in from anywhere.</div>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
            <input type="checkbox" id="ruleCheckinRequireLocation" style="width:18px;height:18px;">
            <span style="font-size:13px;font-weight:600;color:#374151;">Require riders to be at their shift location to check in</span>
          </label>
          <div style="margin-top:14px;max-width:260px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">"At office" radius (m)</label>
            <input type="number" min="30" step="10" id="ruleOfficeAtRadius" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
            <div style="font-size:11px;color:#9ca3af;margin-top:4px;">How close counts as "at the office" — used for the check-in remote flag AND checkout-at-office. Caps each office's own radius (main office is 2000 m, too loose). Default 300.</div>
          </div>
        </div>
      </div>

      <!-- Checkout rule -->
      <div style="border:1px solid #e5e7eb;border-radius:10px;margin-bottom:16px;overflow:hidden;">
        <button type="button" onclick="toggleRuleSection('secCheckout')" style="width:100%;display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:none;padding:12px 14px;cursor:pointer;">
          <span style="font-weight:700;color:#111827;font-size:14px;">📍 Checkout rule</span>
          <span id="secCheckoutChev" style="color:#9ca3af;font-size:13px;">▸</span>
        </button>
        <div id="secCheckout" style="display:none;padding:14px;border-top:1px solid #f1f5f9;">
          <div style="font-size:12px;color:#6b7280;margin-bottom:12px;">When on, a rider can only check out at the office, or at his most recent delivery within the time window. Off = check out anywhere.</div>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:12px;">
            <input type="checkbox" id="ruleCheckoutEnabled" style="width:18px;height:18px;">
            <span style="font-size:13px;font-weight:600;color:#374151;">Require checkout at office or last delivery</span>
          </label>
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:130px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Delivery window (min)</label>
              <input type="number" min="1" step="1" id="ruleCheckoutWindow" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Minutes after marking delivered that he may check out there.</div>
            </div>
            <div style="flex:1;min-width:130px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Delivery radius (m)</label>
              <input type="number" min="10" step="10" id="ruleCheckoutRadius" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">How close his checkout must be to that delivery point.</div>
            </div>
          </div>
        </div>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" onclick="closeAttendanceRules()" style="padding:10px 16px;border-radius:8px;border:1px solid #d1d5db;background:#ffffff;color:#374151;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="button" id="ruleSaveBtn" onclick="saveAttendanceRules()" style="padding:10px 18px;border-radius:8px;border:none;background:#16a34a;color:#ffffff;font-weight:700;cursor:pointer;">Save rules</button>
      </div>
    </div>
  </div>

  <!-- Mark Attendance Modal -->
  <div id="addAttendanceForm" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full p-6" onclick="event.stopPropagation()">
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-semibold text-gray-800">Mark Attendance</h2>
        <button onclick="toggleAddForm()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
      </div>
    
    <!-- Employee and Date Selection -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Select Employee *</label>
        <select 
          id="userSelect" 
          class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 text-base focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          onchange="selectUserFromDropdown()"
        >
          <option value="">-- Select Employee --</option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Date *</label>
        <input 
          id="attDate" 
          type="date" 
          class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 text-base focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          value="<?php echo date('Y-m-d'); ?>"
          onchange="loadExistingAttendance()"
        >
      </div>
    </div>

    <!-- Employee Info -->
    <div id="userInfo" class="mb-4 p-4 bg-blue-50 border-2 border-blue-200 rounded-lg hidden">
      <div class="flex items-center gap-3">
        <span class="text-3xl">👤</span>
        <div>
          <p class="text-base font-semibold text-gray-800"><span id="infoName"></span></p>
          <p class="text-sm text-gray-600">Expected Shift: <span id="infoShift" class="font-medium"></span></p>
        </div>
      </div>
    </div>

    <!-- Time Inputs -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Login Time</label>
        <input 
          id="loginTime" 
          type="time" 
          class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 text-base focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          step="60"
        >
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Logout Time</label>
        <input 
          id="logoutTime" 
          type="time" 
          class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 text-base focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          step="60"
        >
      </div>
    </div>

    <!-- Action Buttons -->
    <div class="grid grid-cols-2 gap-3">
      <button 
        onclick="clearForm()" 
        class="px-6 py-4 bg-gray-100 border-2 border-gray-300 text-gray-700 rounded-lg hover:bg-gray-200 transition font-semibold text-base"
      >
        🗑️ Clear All
      </button>
      <button
        onclick="saveAttendance()"
        style="background:#16a34a;color:#ffffff;"
        class="px-6 py-4 rounded-lg hover:opacity-90 transition font-semibold text-base shadow-lg"
      >
        💾 Save Attendance
      </button>
    </div>
    </div>
  </div>

  <!-- Tabs: Today / Month / Calendar (segmented control) -->
  <div class="mb-4">
    <div class="inline-flex bg-gray-100 rounded-xl p-1 gap-1 border border-gray-200">
      <button id="tabBtnToday" onclick="switchAttTab('today')" class="px-5 py-2 text-sm font-semibold rounded-lg transition bg-white text-blue-700 shadow-sm">📋 Today</button>
      <button id="tabBtnMonth" onclick="switchAttTab('month')" class="px-5 py-2 text-sm font-semibold rounded-lg transition text-gray-600 hover:text-gray-900">📅 Month</button>
      <button id="tabBtnCalendar" onclick="switchAttTab('calendar')" class="px-5 py-2 text-sm font-semibold rounded-lg transition text-gray-600 hover:text-gray-900">🗓️ Calendar</button>
    </div>
  </div>

  <!-- ===== TODAY TAB (existing daily view — unchanged) ===== -->
  <div id="tabToday">

  <!-- Pending leave requests the rider submitted himself — approve/reject without leaving. -->
  <div id="pendingLeavesCard" style="display:none;border:1px solid #FDE68A;background:#FFFBEB;border-radius:10px;padding:12px 14px;margin-bottom:16px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
      <div style="font-size:13px;font-weight:700;color:#92400E;">🏖 Pending leave requests <span id="pendingLeavesCount" style="background:#F59E0B;color:#fff;border-radius:999px;padding:0 8px;font-size:11px;margin-left:4px;">0</span></div>
      <a href="#" onclick="loadPendingLeaves(); return false;" style="font-size:11px;color:#92400E;text-decoration:underline;">refresh</a>
    </div>
    <div id="pendingLeavesBody"></div>
  </div>

  <!-- Company-bike meter issues (info only): overnight grace exceeded and/or no meter reading. -->
  <div id="graceBreachCard" style="display:none;border:1px solid #FCA5A5;background:#FEF2F2;border-radius:10px;padding:12px 14px;margin-bottom:16px;">
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
      <span style="font-size:13px;font-weight:700;color:#991B1B;">🏍 Company bike — meter issues</span>
      <span id="graceBreachCount" style="background:#DC2626;color:#fff;border-radius:999px;padding:0 8px;font-size:11px;">0</span>
      <span style="font-size:11px;color:#9CA3AF;margin-left:4px;">(for information only)</span>
    </div>
    <div id="graceBreachBody"></div>
  </div>

  <!-- Summary Cards - Elegant Horizontal Row -->
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-3 mb-4">
    <div class="flex justify-between items-center mb-2">
      <h3 class="text-sm font-semibold text-gray-700">Summary <span class="font-normal text-gray-400">· selected day</span></h3>
      <a href="#" onclick="switchAttTab('month'); return false;" class="text-xs font-medium" style="color:#2563EB;">Monthly totals →</a>
    </div>

    <!-- Slim stat strip — one number per status, colour-dot coded. Inline styles are
         intentional: the app purges un-compiled utility classes, so colours must be inline. -->
    <div style="display:flex;align-items:stretch;flex-wrap:wrap;">
      <div style="flex:1;min-width:90px;padding:4px 14px 4px 4px;border-right:1px solid #F1F5F9;">
        <div style="display:flex;align-items:baseline;gap:6px;">
          <span style="width:8px;height:8px;border-radius:50%;background:#22C55E;display:inline-block;"></span>
          <span style="font-size:22px;font-weight:700;color:#16A34A;line-height:1;" id="cardPresent">0</span>
        </div>
        <div style="font-size:11px;color:#6B7280;margin-top:3px;">Present</div>
      </div>
      <div style="flex:1;min-width:90px;padding:4px 14px;border-right:1px solid #F1F5F9;">
        <div style="display:flex;align-items:baseline;gap:6px;">
          <span style="width:8px;height:8px;border-radius:50%;background:#3B82F6;display:inline-block;"></span>
          <span style="font-size:22px;font-weight:700;color:#2563EB;line-height:1;" id="cardOnTime">0</span>
        </div>
        <div style="font-size:11px;color:#6B7280;margin-top:3px;">On time</div>
      </div>
      <div style="flex:1;min-width:90px;padding:4px 14px;border-right:1px solid #F1F5F9;">
        <div style="display:flex;align-items:baseline;gap:6px;">
          <span style="width:8px;height:8px;border-radius:50%;background:#F59E0B;display:inline-block;"></span>
          <span style="font-size:22px;font-weight:700;color:#D97706;line-height:1;" id="cardLate">0</span>
        </div>
        <div style="font-size:11px;color:#6B7280;margin-top:3px;">Late</div>
      </div>
      <div style="flex:1;min-width:90px;padding:4px 14px;border-right:1px solid #F1F5F9;">
        <div style="display:flex;align-items:baseline;gap:6px;">
          <span style="width:8px;height:8px;border-radius:50%;background:#8B5CF6;display:inline-block;"></span>
          <span style="font-size:22px;font-weight:700;color:#7C3AED;line-height:1;" id="cardOvertime">0</span>
        </div>
        <div style="font-size:11px;color:#6B7280;margin-top:3px;">Overtime</div>
      </div>
      <div style="flex:1;min-width:90px;padding:4px 14px;border-right:1px solid #F1F5F9;">
        <div style="display:flex;align-items:baseline;gap:6px;">
          <span style="width:8px;height:8px;border-radius:50%;background:#F97316;display:inline-block;"></span>
          <span style="font-size:22px;font-weight:700;color:#EA580C;line-height:1;" id="cardOnLeave">0</span>
        </div>
        <div style="font-size:11px;color:#6B7280;margin-top:3px;">On leave</div>
      </div>
      <div style="flex:1;min-width:90px;padding:4px 4px 4px 14px;">
        <div style="display:flex;align-items:baseline;gap:6px;">
          <span style="width:8px;height:8px;border-radius:50%;background:#EF4444;display:inline-block;"></span>
          <span style="font-size:22px;font-weight:700;color:#DC2626;line-height:1;" id="cardAbsent">0</span>
        </div>
        <div style="font-size:11px;color:#6B7280;margin-top:3px;">Absent</div>
      </div>
    </div>
  </div>

  <!-- Main Attendance Table -->
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm">
    <!-- Date Navigation & Filters -->
    <div class="p-4 border-b border-gray-200 bg-gray-50">
      <div class="flex flex-wrap items-center gap-3 justify-between">
        <!-- Date Navigation -->
        <div class="flex items-center gap-2">
          <button onclick="navigateDate(-1)" class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 transition">
            ← Prev
          </button>
          <input 
            type="date" 
            id="tableDate" 
            value="<?php echo date('Y-m-d'); ?>"
            onchange="loadAttendanceForDate()"
            class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          >
          <button onclick="navigateDate(1)" class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 transition">
            Next →
          </button>
          <button onclick="goToToday()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
            Today
          </button>
        </div>

        <!-- Filters (moved here from the header — they only affect the Today view) -->
        <div class="flex items-center gap-2 flex-wrap">
          <select id="activeFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="onActiveFilterChange()" title="Active vs all users">
            <option value="active" selected>Active users</option>
            <option value="all">All users</option>
          </select>
          <select id="userFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="loadAttendanceForDate()" title="Role">
            <option value="all">Everyone</option>
            <option value="riders">Riders only</option>
            <option value="staff">Staff only</option>
          </select>
          <select id="locationFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="applyRowFilters()" title="Location">
            <option value="all" selected>All locations</option>
            <option value="onsite">On-site</option>
            <option value="remote">Remote</option>
            <option value="no_location">No location</option>
          </select>
          <select id="statusFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="applyRowFilters()" title="Status">
            <option value="all">All status</option>
            <option value="present">Present</option>
            <option value="late">Late</option>
            <option value="overtime">Overtime</option>
            <option value="absent">Absent</option>
          </select>
          <select id="bikeFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="applyRowFilters()" title="Vehicle">
            <option value="all">All riders</option>
            <option value="bike">Company bike</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Meter-attention strip (only shows when something needs attention today) -->
    <div id="meterAttention" class="hidden px-4 pb-2 flex flex-wrap gap-2"></div>

    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Employee</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Day timeline</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Day</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody id="attBody" class="bg-white divide-y divide-gray-200">
          <tr>
            <td colspan="4" class="px-4 py-8 text-center text-gray-500 text-sm">Loading attendance records...</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  </div><!-- /#tabToday -->

  <!-- ===== MONTH TAB ===== -->
  <div id="tabMonth" class="hidden">
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-4 mb-4 flex items-center justify-between flex-wrap gap-3">
      <div class="flex items-center gap-2">
        <button onclick="monthStep(-1)" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">← Prev</button>
        <input type="month" id="monthPicker" onchange="loadMonthTab()" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
        <button onclick="monthStep(1)" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">Next →</button>
      </div>
      <div class="flex items-center gap-2">
        <span class="text-xs text-gray-500">Month totals — plus leave and absences for the year cycle <span id="monthCycleLabel" style="font-weight:600;color:#6b7280;"></span>.</span>
        <button onclick="exportMonthCsv()" class="px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-sm hover:bg-gray-50">📥 CSV</button>
      </div>
    </div>
    {{-- Payroll leave actions due for this month. Filled by loadPayrollLeaveBanner(); stays
         hidden for anyone without payroll access (the endpoint 403s) and for months with
         nothing to settle. --}}
    <div id="leaveActBanner" style="display:none;" class="mb-4"></div>
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Employee</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Present</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Absent (mo)</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Leave (mo)</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Late (mo)</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-emerald-600 uppercase">OT (mo)</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-amber-700 uppercase" title="Day-flags this month — 🌅 morning (late ride-in / no home start / meter gap) · 🌙 evening (home late / meter locked / unlocked) · 🏢 delivered but checked out at the office. Click any chip for the days.">Flags</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-orange-700 uppercase" title="Finished working days with a missing meter reading (start and/or closing). Absent days, leave, today's running shift and future days are never counted.">Meter</th>
            <th class="px-4 py-3 text-center text-xs font-semibold uppercase" style="color:#B45309;" title="Kilometres a COMPANY bike or van moved between his evening reading and his next morning reading — the same number as Bikes → Riders. Own bikes never count; 1 km or less is meter rounding. Click for each night.">Off-duty km</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-purple-500 uppercase">Leave (bal)</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-red-500 uppercase">Absent (yr)</th>
          </tr>
        </thead>
        <tbody id="monthBody" class="bg-white divide-y divide-gray-100">
          <tr><td colspan="11" class="px-4 py-8 text-center text-gray-400 text-sm">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ===== CALENDAR TAB ===== -->
  <div id="tabCalendar" class="hidden">
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-4 mb-4 flex items-center justify-between flex-wrap gap-3">
      <div class="flex items-center gap-2">
        <button onclick="calStep(-1)" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">←</button>
        <span id="calLabel" class="text-sm font-semibold text-gray-800 min-w-[140px] text-center"></span>
        <button onclick="calStep(1)" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">→</button>
      </div>
      <div class="flex items-center gap-3 text-xs" style="color:#6B7280;">
        <span class="inline-flex items-center gap-1"><span style="width:10px;height:10px;background:#FEE2E2;border:1px solid #FCA5A5;border-radius:3px;display:inline-block;"></span> Holiday</span>
        <span id="calHint">Click a day to add a holiday — or click a start day then an end day for a range.</span>
        <button id="calCancelBtn" onclick="calCancelSelect()" style="display:none;background:#EF4444;color:#fff;padding:3px 10px;border-radius:6px;font-weight:600;">Cancel</button>
      </div>
    </div>
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-4">
      <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:6px;" class="text-center text-xs font-semibold text-gray-400">
        <div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div><div>Sun</div>
      </div>
      <div id="calGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;"></div>
    </div>
  </div>
</div>

<!-- Meter correction modal (R7c) -->
<div id="meterEditModal" style="display:none;position:fixed;inset:0;z-index:10002;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)closeMeterEdit()">
  <div style="background:#fff;border-radius:14px;max-width:400px;width:100%;padding:20px;" onclick="event.stopPropagation()">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
      <h3 style="font-size:16px;font-weight:700;color:#111827;">Correct meter reading</h3>
      <button type="button" onclick="closeMeterEdit()" style="background:none;border:none;font-size:22px;color:#9ca3af;cursor:pointer;">&times;</button>
    </div>
    <div id="meterEditWho" style="font-size:12px;color:#6b7280;margin-bottom:14px;"></div>
    <div style="display:flex;gap:12px;">
      <div style="flex:1;">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Start meter</label>
        <input type="number" min="0" step="1" id="meterEditStart" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
      </div>
      <div style="flex:1;">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">End meter</label>
        <input type="number" min="0" step="1" id="meterEditEnd" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
      </div>
    </div>
    {{-- ⭐⭐ WHICH MACHINE THESE READINGS ARE OF (Aug-27 2026).
         A rider gets ONE meter pair a day. On a day he arrives on his own bike and takes
         the company van out, that pair has to describe two machines — and until somebody
         says which is which, his own-bike petrol claim has no evidence to stand on and
         the van's odometer can silently absorb his bike's numbers (exactly what happened
         in the Aug-22 incident). The manager is the only one who knows, so this is where
         he says it. Left alone, nothing changes: any existing stamp is preserved. --}}
    <div id="meterEditVehWrap" style="display:none;margin-top:12px;">
      <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Which vehicle are these readings for?</label>
      <select id="meterEditVehicle" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
        <option value="">— leave as recorded —</option>
      </select>
      <div id="meterEditVehHint" style="font-size:11px;color:#6b7280;margin-top:4px;"></div>
    </div>
    <div style="font-size:11px;color:#9ca3af;margin-top:8px;">Leave a field blank to keep it unchanged. This only fixes the attendance meter — the rider re-raises his petrol request to recompute the amount.</div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
      <button type="button" onclick="closeMeterEdit()" style="padding:9px 14px;border-radius:8px;border:1px solid #e5e7eb;background:#fff;color:#374151;font-weight:600;cursor:pointer;">Cancel</button>
      <button type="button" id="meterEditSaveBtn" onclick="saveMeterEdit()" style="padding:9px 16px;border-radius:8px;border:none;background:#2563EB;color:#fff;font-weight:700;cursor:pointer;">Save</button>
    </div>
  </div>
</div>

{{-- RIDER BYPASSES — the ONE place a manager unlocks a stuck rider: checkout-from-anywhere
     (non-bike rider forgot to check out and went home), home-meter unlock for a late
     company-bike rider, or entering the home meter for a dead phone. Opened from the 🔓/🏍
     chips on any rider's attendance row. --}}
<div id="homeValveModal" style="display:none;position:fixed;inset:0;z-index:10002;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)closeHomeValve()">
  <div style="background:#fff;border-radius:14px;max-width:440px;width:100%;padding:20px;max-height:92vh;overflow-y:auto;" onclick="event.stopPropagation()">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
      <h3 style="font-size:16px;font-weight:700;color:#111827;">🔓 Rider bypasses</h3>
      <button type="button" onclick="closeHomeValve()" style="background:none;border:none;font-size:22px;color:#9ca3af;cursor:pointer;">&times;</button>
    </div>
    <div id="homeValveWho" style="font-size:12px;color:#6b7280;margin-bottom:12px;"></div>
    <div id="homeValveInfo" style="font-size:12.5px;color:#374151;background:#F9FAFB;border:1px solid #F3F4F6;border-radius:8px;padding:10px 12px;margin-bottom:14px;line-height:1.6;"></div>

    {{-- U5 — Unlock CHECK-IN (morning lock: past the ride-to-work deadline, or skipped the
         home start once the lock is live). Harmless to grant while the lock config is off. --}}
    <div id="homeValveCheckinSec" style="display:none;border:1px solid #F3F4F6;border-radius:8px;padding:12px;margin-bottom:12px;">
      <div style="font-size:13px;font-weight:700;color:#111827;margin-bottom:2px;">Unlock check-in</div>
      <div style="font-size:11px;color:#6b7280;margin-bottom:8px;">For a company-bike rider blocked at the door (arrived after his ride-in deadline, or no start meter from home). Opens his IN button for 10 minutes.</div>
      <div id="homeValveCiActive" style="display:none;font-size:12px;font-weight:700;color:#92400E;background:#FEF3C7;border:1px solid #FDE68A;border-radius:6px;padding:6px 10px;margin-bottom:8px;"></div>
      <textarea id="homeValveCiReason" rows="2" placeholder="Reason (required) — e.g. GPS broken, bike at mechanic…" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;resize:vertical;"></textarea>
      <div style="display:flex;justify-content:flex-end;margin-top:8px;">
        <button type="button" id="homeValveCiBtn" onclick="homeValveCheckinUnlock()" style="padding:8px 14px;border-radius:8px;border:none;background:#B45309;color:#fff;font-weight:700;cursor:pointer;">🔓 Unlock check-in</button>
      </div>
    </div>

    {{-- Checkout-from-anywhere unlock (rider still checked in, blocked by the checkout rule) --}}
    <div id="homeValveCheckoutSec" style="display:none;border:1px solid #F3F4F6;border-radius:8px;padding:12px;margin-bottom:12px;">
      <div style="font-size:13px;font-weight:700;color:#111827;margin-bottom:2px;">Allow checkout from anywhere</div>
      <div style="font-size:11px;color:#6b7280;margin-bottom:8px;">For a rider who forgot to check out at his last delivery / the office and left. Opens his OUT button for <span id="homeValveCoMins">10</span> minutes from wherever he is (his GPS still records where that was).</div>
      <div id="homeValveCoActive" style="display:none;font-size:12px;font-weight:700;color:#92400E;background:#FEF3C7;border:1px solid #FDE68A;border-radius:6px;padding:6px 10px;margin-bottom:8px;"></div>
      <textarea id="homeValveCoReason" rows="2" placeholder="Reason (required) — e.g. forgot to check out, already home…" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;resize:vertical;"></textarea>
      <div style="display:flex;justify-content:flex-end;margin-top:8px;">
        <button type="button" id="homeValveCoBtn" onclick="homeValveCheckoutUnlock()" style="padding:8px 14px;border-radius:8px;border:none;background:#B45309;color:#fff;font-weight:700;cursor:pointer;">🔓 Unlock checkout</button>
      </div>
    </div>

    {{-- UNDO CHECKOUT — the mirror of the section above: shown only once he HAS checked out.
         An extra order came in and he is going back out. --}}
    <div id="homeValveUndoSec" style="display:none;border:1px solid #FECACA;background:#FEF2F2;border-radius:8px;padding:12px;margin-bottom:12px;">
      <div style="font-size:13px;font-weight:700;color:#991B1B;margin-bottom:2px;">Undo checkout — put him back on duty</div>
      <div style="font-size:11px;color:#B91C1C;margin-bottom:8px;">For an extra order after he already finished. This clears his <b>closing meter</b>, checkout location, road distance and overtime — he will be asked for the meter again at his real checkout. His location tracking restarts on its own when he next opens the app. Today or yesterday only.</div>
      <div id="homeValveUndoWhat" style="font-size:11px;color:#7F1D1D;background:#FEE2E2;border:1px solid #FCA5A5;border-radius:6px;padding:6px 10px;margin-bottom:8px;"></div>
      <textarea id="homeValveUndoReason" rows="2" placeholder="Reason (required) — e.g. extra order for G-11, going back out" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;resize:vertical;"></textarea>
      <div style="display:flex;justify-content:flex-end;margin-top:8px;">
        <button type="button" id="homeValveUndoBtn" onclick="homeValveUndoCheckout()" style="padding:8px 14px;border-radius:8px;border:none;background:#B91C1C;color:#fff;font-weight:700;cursor:pointer;">↩ Undo checkout</button>
      </div>
    </div>

    {{-- Completed --}}
    <div id="homeValveDone" style="display:none;font-size:13px;color:#166534;background:#DCFCE7;border:1px solid #BBF7D0;border-radius:8px;padding:10px 12px;"></div>

    {{-- Unlock the rider's phone (timed) --}}
    <div id="homeValveUnlockSec" style="display:none;border:1px solid #F3F4F6;border-radius:8px;padding:12px;margin-bottom:12px;">
      <div style="font-size:13px;font-weight:700;color:#111827;margin-bottom:2px;">Unlock the rider's phone</div>
      <div style="font-size:11px;color:#6b7280;margin-bottom:8px;">Opens meter entry on his app for <span id="homeValveUnlockMins">10</span> minutes. He still records it himself (with his photo).</div>
      <textarea id="homeValveReason" rows="2" placeholder="Reason (required) — e.g. traffic, went to buy fuel…" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;resize:vertical;"></textarea>
      <div style="display:flex;justify-content:flex-end;margin-top:8px;">
        <button type="button" id="homeValveUnlockBtn" onclick="homeValveUnlock()" style="padding:8px 14px;border-radius:8px;border:none;background:#B45309;color:#fff;font-weight:700;cursor:pointer;">🔓 Unlock for rider</button>
      </div>
    </div>

    {{-- Or enter the meter for him (dead phone) --}}
    <div id="homeValveEnterSec" style="display:none;border:1px solid #F3F4F6;border-radius:8px;padding:12px;">
      <div style="font-size:13px;font-weight:700;color:#111827;margin-bottom:2px;">Or enter the meter for him</div>
      <div style="font-size:11px;color:#6b7280;margin-bottom:8px;">Use only if his phone can't record it. Closes the day for him.</div>
      <div style="display:flex;gap:8px;align-items:flex-end;">
        <div style="flex:1;">
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Home meter (km)</label>
          <input type="number" min="0" step="1" id="homeValveMeter" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
        </div>
        <button type="button" id="homeValveEnterBtn" onclick="homeValveEnter()" style="padding:9px 14px;border-radius:8px;border:none;background:#2563EB;color:#fff;font-weight:700;cursor:pointer;white-space:nowrap;">Save meter</button>
      </div>
      <input type="text" id="homeValveEnterReason" placeholder="Reason (optional)" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;margin-top:8px;">
    </div>

    <div style="display:flex;justify-content:flex-end;margin-top:14px;">
      <button type="button" onclick="closeHomeValve()" style="padding:9px 14px;border-radius:8px;border:1px solid #e5e7eb;background:#fff;color:#374151;font-weight:600;cursor:pointer;">Close</button>
    </div>
  </div>
</div>

<!-- Quick Add Time Modal - Modern Design -->
<div id="quickTimeModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center p-4 backdrop-blur-sm" style="z-index: 9999;">
  <div id="quickTimeCard" class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-2xl max-w-lg w-full p-8 transform transition-all" onclick="event.stopPropagation()">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center gap-3">
        <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
          <span class="text-2xl">⏰</span>
        </div>
        <div>
          <h3 class="text-xl font-bold text-gray-900" id="quickTimeModalTitle">Mark Time</h3>
          <p class="text-sm text-gray-500">Quick attendance marking</p>
        </div>
      </div>
      <button onclick="closeQuickTime()" class="w-10 h-10 rounded-full hover:bg-gray-200 transition flex items-center justify-center text-gray-400 hover:text-gray-600">
        <span class="text-2xl leading-none">&times;</span>
      </button>
    </div>
    
    <!-- Employee Info Card -->
    <div class="bg-white rounded-xl p-4 mb-6 border border-gray-100 shadow-sm">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-gradient-to-br from-green-400 to-cyan-500 rounded-full flex items-center justify-center text-white font-bold">
          <span id="quickTimeUserInitial">?</span>
        </div>
        <div class="flex-1">
          <p class="font-semibold text-gray-900" id="quickTimeUser"></p>
          <p class="text-xs text-gray-500" id="quickTimeDate"></p>
        </div>
      </div>
    </div>

    <!-- Time Inputs - Conditional Display -->
    <div class="space-y-4 mb-6">
      <div id="loginTimeSection" class="hidden">
        <label class="block text-sm font-semibold text-gray-700 mb-2">🌅 Login Time</label>
        <div class="relative">
          <input 
            id="quickLoginTimeInput" 
            type="time" 
            class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 text-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
          >
        </div>
      </div>

      <div id="logoutTimeSection" class="hidden">
        <label class="block text-sm font-semibold text-gray-700 mb-2">🌆 Logout Time</label>
        <div class="relative">
          <input 
            id="quickLogoutTimeInput" 
            type="time" 
            class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 text-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition"
          >
        </div>
      </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex gap-3">
      <button 
        onclick="closeQuickTime()" 
        class="flex-1 px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition font-semibold"
      >
        Cancel
      </button>
      <button 
        onclick="saveQuickTime()" 
        class="flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-xl hover:from-blue-700 hover:to-purple-700 transition font-semibold shadow-lg"
      >
        💾 Save Time
      </button>
    </div>
  </div>
</div>

<!-- Quick Edit Modal -->
<div id="quickEditModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
  <div id="quickEditCard" class="bg-white rounded-lg shadow-xl max-w-md w-full p-6" onclick="event.stopPropagation()">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-lg font-semibold text-gray-900">Edit Attendance</h3>
      <button onclick="closeQuickEdit()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
    </div>
    
    <div class="mb-4">
      <p class="text-sm text-gray-600 mb-1">Employee:</p>
      <p class="text-base font-semibold text-gray-800" id="quickEditUser"></p>
      <p class="text-xs text-gray-500" id="quickEditDate"></p>
    </div>

    <div class="grid grid-cols-2 gap-4 mb-6">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Login Time</label>
        <input 
          id="quickLoginTime" 
          type="time" 
          class="w-full border-2 border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"
        >
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Logout Time</label>
        <input 
          id="quickLogoutTime" 
          type="time" 
          class="w-full border-2 border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"
        >
      </div>
    </div>

    {{-- Phase 0 (Sep-6 2026) — a typed time sets the day's overtime, so OVERWRITING a recorded
         one asks why. Appears only when a stored value is actually being changed; adding a
         missing check-in/out stays a two-click fix. The server enforces the same rule. --}}
    <div id="quickEditReasonBox" style="display:none;margin:-8px 0 20px;">
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Why is this being changed? <span style="color:#b91c1c;">*</span>
      </label>
      <input
        id="quickEditReason"
        type="text"
        maxlength="200"
        placeholder="e.g. forgot to press OUT, meter photo shows 21:30"
        class="w-full border-2 border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"
      >
      <p id="quickEditReasonWhy" style="font-size:11px;color:#6b7280;margin-top:6px;"></p>
    </div>

    <div class="flex gap-2">
      <button
        onclick="closeQuickEdit()"
        class="flex-1 px-4 py-3 bg-gray-100 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-200 transition font-medium"
      >
        Cancel
      </button>
      <button
        onclick="saveQuickEdit()"
        class="flex-1 px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium"
      >
        💾 Save
      </button>
    </div>
  </div>
</div>

<!-- Apply Leave Modal (manager applies leave on a rider's behalf → auto-approved) -->
<div id="applyLeaveModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;padding:16px;" onclick="closeApplyLeave()">
  <div class="bg-white rounded-xl shadow-2xl w-full max-w-md" style="max-height:90vh;overflow-y:auto;" onclick="event.stopPropagation();">
    <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
      <h3 class="text-lg font-semibold text-gray-900">📝 Apply leave</h3>
      <button onclick="closeApplyLeave()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
    </div>
    <div class="p-5 space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Rider / staff</label>
        <select id="leaveUser" onchange="loadLeaveBalanceChip()" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"></select>
        <div id="leaveBalanceChip" style="margin-top:6px;font-size:12px;color:#6b7280;display:flex;align-items:center;gap:8px;flex-wrap:wrap;"></div>
        <div id="leaveHistoryBox" style="display:none;margin-top:6px;font-size:12px;border-top:1px dashed #e5e7eb;padding-top:6px;"></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">From</label>
          <input type="date" id="leaveFrom" onchange="syncLeaveToMin()" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">To</label>
          <input type="date" id="leaveTo" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Leave type</label>
        <select id="leaveType" onchange="onLeaveTypeChange()" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
          <option value="planned" selected>📅 Planned (applied in advance)</option>
          <option value="emergency">⚡ Emergency (same-day)</option>
          <option value="half_day">🌗 Half day (0.5 leave)</option>
        </select>
        <p class="text-xs text-gray-500" id="leaveTypeHint" style="margin-top:4px;">No time cutoff applies to you. Emergency counts toward the rider's same-day allowance.</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Note <span class="text-gray-400 font-normal">(optional)</span></label>
        <input type="text" id="leaveNote" maxlength="500" placeholder="e.g. family emergency" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
      </div>
      <p class="text-xs text-gray-500">You're granting this leave — it's approved right away and logged under your name. Days on leave won't be counted as absent.</p>
    </div>
    <div class="px-5 py-4 border-t border-gray-200 flex justify-end gap-2">
      <button onclick="closeApplyLeave()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm">Cancel</button>
      <button id="leaveSubmitBtn" onclick="submitApplyLeave()" style="background:#16a34a;color:#ffffff;" class="px-4 py-2 rounded-lg hover:opacity-90 text-sm font-semibold">Approve leave</button>
    </div>
  </div>
</div>

<!-- Shift Manager Modal -->
<div id="shiftModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
  <div id="shiftModalCard" class="bg-white rounded-xl shadow-2xl w-full overflow-hidden" onclick="event.stopPropagation();" style="width: min(92vw, 900px); max-height: 85vh;">
    <!-- Sticky Header -->
    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-white sticky top-0 z-10">
      <h3 class="text-xl font-semibold text-gray-900">Manage Employee Shifts</h3>
      <button type="button" onclick="closeShiftManager()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
    </div>
    <!-- Scrollable Body -->
    <div class="px-6 py-4" style="max-height: calc(85vh - 64px); overflow-y: auto;">
      <div id="shiftList" class="space-y-1 divide-y divide-gray-100">
        <!-- Populated by JS -->
      </div>
    </div>
  </div>
</div>

<!-- Employee Details Modal (Last 30 Days with Order Stats) -->
<div id="employeeDetailsModal" style="display: none;" onclick="if(event.target === this) closeEmployeeDetails();">
  <div style="background: white; border-radius: 16px; width: 95%; max-width: 1200px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);" onclick="event.stopPropagation();">
    
    <!-- Header -->
    <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
      <div style="display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 40px; height: 40px; border-radius: 50%; background: #dbeafe; display: flex; align-items: center; justify-content: center; color: #2563eb; font-size: 18px; font-weight: bold;">
            👤
          </div>
          <div>
            <h3 style="font-size: 18px; font-weight: 600; color: #111827; margin: 0;" id="detailsEmployeeName">Employee Name</h3>
            <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;" id="detailsDateRange">Date Range</p>
          </div>
        </div>
        <button type="button" onclick="closeEmployeeDetails()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
      </div>
    </div>

    <!-- Stats Bar — Present/Late/Overtime/Absent FILTER the table below (click again to clear);
         On Leave opens the yearly leaves popup (with undo). -->
    <div style="padding: 12px 24px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; flex-shrink: 0;">
      <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px;">
        <div id="empTilePresent" onclick="setEmpDetailFilter('present')" title="Show only days he was in" style="text-align: center; cursor: pointer; border-radius: 8px; padding: 2px;">
          <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Present</p>
          <p style="font-size: 16px; font-weight: bold; color: #111827; margin: 0;" id="detailsStatPresent">0</p>
        </div>
        <div id="empTileLate" onclick="setEmpDetailFilter('late')" title="Show only late days" style="text-align: center; cursor: pointer; border-radius: 8px; padding: 2px;">
          <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Late</p>
          <p style="font-size: 16px; font-weight: bold; color: #dc2626; margin: 0;" id="detailsStatLate">0</p>
        </div>
        <div id="empTileOvertime" onclick="setEmpDetailFilter('overtime')" title="Show only overtime days" style="text-align: center; cursor: pointer; border-radius: 8px; padding: 2px;">
          <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Overtime</p>
          <p style="font-size: 16px; font-weight: bold; color: #16a34a; margin: 0;" id="detailsStatOT">0</p>
          <p style="font-size: 9.5px; font-weight: 700; color: #6d28d9; margin: 1px 0 0 0; display: none;" id="detailsStatOTBonus"></p>
        </div>
        <div id="empTileLeave" onclick="openEmpDetailLeaves()" title="Yearly leaves — view / undo" style="text-align: center; cursor: pointer; border-radius: 8px; padding: 2px;">
          <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">On Leave</p>
          <p style="font-size: 16px; font-weight: bold; color: #ea580c; margin: 0;" id="detailsStatOnLeave">0</p>
        </div>
        <div id="empTileAbsent" onclick="setEmpDetailFilter('absent')" title="Show only absent days" style="text-align: center; cursor: pointer; border-radius: 8px; padding: 2px;">
          <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Absent</p>
          <p style="font-size: 16px; font-weight: bold; color: #6b7280; margin: 0;" id="detailsStatAbsent">0</p>
        </div>
        <div style="text-align: center;">
          <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Total Hours</p>
          <p style="font-size: 16px; font-weight: bold; color: #111827; margin: 0;" id="detailsStatHours">0h</p>
        </div>
        <div style="text-align: center;">
          <p style="font-size: 10px; color: #2563eb; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Orders</p>
          <p style="font-size: 16px; font-weight: bold; color: #2563eb; margin: 0;" id="detailsStatOrders">0</p>
        </div>
      </div>
      <div id="empDetailFilterHint" style="display:none; margin-top:8px; font-size:12px; color:#374151;"></div>
    </div>

    <!-- Scrollable Table Container -->
    <div style="flex: 1 1 auto; overflow-y: auto; overflow-x: auto; min-height: 0; background: white;">
      <table style="width: 100%; border-collapse: collapse; min-width: 900px;">
        <thead style="position: sticky; top: 0; background: #f3f4f6; z-index: 10;">
          <tr>
            <th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Date</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Status</th>
            <th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Login</th>
            <th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Logout</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Hours</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Late By</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Overtime</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #2563eb; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Orders</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #2563eb; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">1st Delivery</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #2563eb; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Last Delivery</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #16a34a; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Meter</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Pics</th>
          </tr>
        </thead>
        <tbody id="employeeDetailsBody" style="background: white;">
          <tr>
            <td colspan="12" style="padding: 20px; text-align: center; color: #6b7280;">Loading...</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Footer -->
    <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: white; flex-shrink: 0; display: flex; justify-content: flex-end;">
      <button 
        type="button"
        onclick="closeEmployeeDetails()" 
        style="padding: 10px 24px; background: #2563eb; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);"
      >
        Close
      </button>
    </div>

  </div>
</div>

<!-- Meter Picture Viewer Modal -->
<div id="meterPictureModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 10001; background-color: rgba(0, 0, 0, 0.9); align-items: center; justify-content: center; padding: 1rem;" onclick="if(event.target === this) closeMeterPictureModal();">
  <div style="position: relative; max-width: 90%; max-height: 90%; display: flex; flex-direction: column; align-items: center;" onclick="event.stopPropagation();">
    <button onclick="closeMeterPictureModal()" style="position: absolute; top: -40px; right: 0; background: rgba(255, 255, 255, 0.2); color: white; border: none; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 16px; font-weight: 600;">✕ Close</button>
    <img id="meterPictureImage" src="" style="max-width: 100%; max-height: 90vh; border-radius: 8px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);" />
  </div>
</div>

{{-- Shared "Change shift" popup (used by the clickable Expected Shift column) --}}
@include('partials.shift-change-modal')

<!-- Customize User List Modal -->
<div id="customizeUserListModal" style="display: none;" onclick="if(event.target === this) closeCustomizeUserList();">
  <div style="background: white; border-radius: 16px; width: 95%; max-width: 800px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);" onclick="event.stopPropagation();">
    <!-- Header -->
    <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
      <div style="display: flex; align-items: center; justify-content: space-between;">
        <div>
          <h2 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">People &amp; Rider List</h2>
          <p style="font-size: 14px; color: #6b7280; margin-top: 4px;"><b>Show in Attendance</b> = appears in the attendance screens. <b>Show in Salary</b> = appears on the Payroll screen. <b>Delivery Rider</b> = appears in the rider-assign lists on web &amp; mobile. All independent — e.g. an office person can be on Payroll without appearing in attendance.</p>
        </div>
        <button onclick="closeCustomizeUserList()" style="color: #9ca3af; font-size: 28px; line-height: 1; border: none; background: none; cursor: pointer; padding: 0;" onmouseover="this.style.color='#4b5563'" onmouseout="this.style.color='#9ca3af'">
          ×
        </button>
      </div>
    </div>
    
    <!-- Users List (Scrollable) -->
    <div style="overflow-y: auto; flex: 1; padding: 24px;">
      <table style="width: 100%;">
        <thead style="background: #f9fafb; position: sticky; top: 0;">
          <tr style="text-align: left; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase;">
            <th style="padding: 12px;">Employee</th>
            <th style="padding: 12px;">Role</th>
            <th style="padding: 12px; width: 150px; text-align:center;">Show in Attendance</th>
            <th id="salaryVisHeader" style="padding: 12px; width: 130px; text-align:center; display:none;">Show in Salary</th>
            <th style="padding: 12px; width: 140px; text-align:center;">Delivery Rider</th>
          </tr>
        </thead>
        <tbody id="usersVisibilityTableBody">
          <tr><td colspan="5" style="padding: 20px; text-align: center; color: #6b7280;">Loading...</td></tr>
        </tbody>
      </table>
    </div>
    
    <!-- Footer -->
    <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center;">
      <div style="font-size: 13px; color: #6b7280;">
        <span id="visibleUsersCount">0</span> users will appear in attendance tracking
      </div>
      <div style="display: flex; gap: 12px;">
        <button onclick="closeCustomizeUserList()" style="padding: 10px 20px; background: white; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; cursor: pointer; font-weight: 500;">
          Cancel
        </button>
        <button onclick="saveUserVisibilityChanges()" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">
          Save Changes
        </button>
      </div>
    </div>
  </div>
</div>

<!-- GPS Audit Modal -->
<div id="gpsAuditModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 99999; background: rgba(0, 0, 0, 0.6); align-items: center; justify-content: center; padding: 1rem;" onclick="if(event.target === this) closeGpsAudit();">
  <div style="background: white; border-radius: 16px; width: 95%; max-width: 700px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);" onclick="event.stopPropagation();">
    
    <!-- Header -->
    <div style="padding: 16px 24px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
      <div>
        <h3 style="font-size: 18px; font-weight: 600; color: #111827; margin: 0;" id="gpsAuditTitle">GPS Tracking Audit</h3>
        <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0 0;" id="gpsAuditSubtitle">Loading...</p>
      </div>
      <button onclick="closeGpsAudit()" style="background: none; border: none; color: #9ca3af; font-size: 28px; cursor: pointer; padding: 0 8px;">&times;</button>
    </div>
    
    <!-- Content -->
    <div id="gpsAuditContent" style="flex: 1; overflow-y: auto; padding: 20px 24px;">
      <div style="text-align: center; padding: 40px; color: #6b7280;">
        <div style="font-size: 24px; margin-bottom: 8px;">⏳</div>
        Loading GPS data...
      </div>
    </div>
    
    <!-- Footer -->
    <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; flex-shrink: 0; display: flex; justify-content: flex-end;">
      <button onclick="closeGpsAudit()" style="padding: 10px 24px; background: #2563eb; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">
        Close
      </button>
    </div>
  </div>
</div>

<style>
  #customizeUserListModal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow-y: auto;
  }
  
  /* GPS Audit Styles */
  .gps-audit-stat {
    text-align: center;
    padding: 12px;
    background: #f9fafb;
    border-radius: 8px;
  }
  .gps-audit-stat .value {
    font-size: 24px;
    font-weight: 700;
    color: #111827;
  }
  .gps-audit-stat .label {
    font-size: 11px;
    color: #6b7280;
    text-transform: uppercase;
  }
  .gps-gap-item {
    display: flex;
    align-items: center;
    padding: 10px 12px;
    border-radius: 8px;
    margin-bottom: 8px;
  }
  .gps-gap-critical {
    background: #fef2f2;
    border-left: 3px solid #ef4444;
  }
  .gps-gap-warning {
    background: #fffbeb;
    border-left: 3px solid #f59e0b;
  }
  .gps-gap-info {
    background: #eff6ff;
    border-left: 3px solid #3b82f6;
  }
  .gps-timeline {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 12px;
  }
  .gps-timeline-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #22c55e;
  }
  .gps-timeline-dot.gap {
    background: #ef4444;
    width: 16px;
  }
</style>

{{-- THE leave-actions row renderer, shared with the Payroll screen. Included before this
     page's script so NFLeaveActions exists by the time the panel is opened. --}}
@include('partials.leave-actions-panel')

<script>
let allUsers = [];
let showOnlyActive = true; // default
let selectedUserData = null;
let currentEditUserId = null;
let currentEditDate = null;
let employeeDetailsData = null; // Store employee details modal data
let allAttendanceData = [];
let currentSummaryPeriod = 'day'; // 'day' or 'month'
let currentTimeModalMode = null; // 'login' or 'logout'
let allUsersVisibility = [];
let visibilityChanges = {};
let deliveryRiderChanges = {};
let salaryVisChanges = {};
let salaryToggleAvailable = false;

// Initialize on page load
document.addEventListener('DOMContentLoaded', async function() {
  await loadAllUsers();
  loadAttendanceForDate();
  loadPendingLeaves();

  // Event delegation for action buttons
  const tbody = document.getElementById('attBody');
  if (tbody) {
    tbody.addEventListener('click', function(e) {
      const target = e.target.closest('button');
      if (!target) return;
      
      // Handle quick add button (+ button)
      if (target.classList.contains('quick-add-btn')) {
        e.preventDefault();
        e.stopPropagation();
        const userId = parseInt(target.dataset.userId);
        const userName = target.dataset.userName;
        quickAddLogout(userId, userName);
      }
      
      // Handle manage shift button (📅 button)
      if (target.classList.contains('manage-shift-btn')) {
        e.preventDefault();
        e.stopPropagation();
        const userId = parseInt(target.dataset.userId);
        const userName = target.dataset.userName;
        // Use the in-place popup (same as clicking the Expected Shift) instead of
        // redirecting away to the shifts page.
        openShiftChange({ userId: userId, userName: userName, onSaved: loadAttendanceForDate });
      }
      
      // Handle quick edit button (✏️ button)
      if (target.classList.contains('quick-edit-btn')) {
        e.preventDefault();
        e.stopPropagation();
        const userId = parseInt(target.dataset.userId);
        const userName = target.dataset.userName;
        const loginTime = target.dataset.loginTime;
        const logoutTime = target.dataset.logoutTime;
        const attendanceDate = target.dataset.attendanceDate;
        openQuickEdit(userId, userName, loginTime, logoutTime, attendanceDate);
      }
    });
  }
});

// Toggle add form modal
function toggleAddForm() {
  const modal = document.getElementById('addAttendanceForm');
  if (modal.classList.contains('hidden')) {
    modal.classList.remove('hidden');
    modal.onclick = function(e) {
      if (e.target === modal) {
        toggleAddForm();
      }
    };
  } else {
    modal.classList.add('hidden');
    clearForm();
  }
}

// Date navigation
function navigateDate(days) {
  const dateInput = document.getElementById('tableDate');
  const currentDate = new Date(dateInput.value);
  currentDate.setDate(currentDate.getDate() + days);
  dateInput.value = currentDate.toISOString().split('T')[0];
  loadAttendanceForDate();
}

function goToToday() {
  document.getElementById('tableDate').value = new Date().toISOString().split('T')[0];
  loadAttendanceForDate();
}

// Load all users for dropdown
async function loadAllUsers() {
  try {
    const endpoint = showOnlyActive ? '/users/all' : '/users/all?include_inactive=1';
    const res = await fetch(endpoint);
    const json = await res.json();
    allUsers = json.data || [];
    populateUserDropdowns();
  } catch(e) {
    console.error('Failed to load users', e);
  }
}

function onActiveFilterChange() {
  const val = document.getElementById('activeFilter').value;
  showOnlyActive = (val === 'active');
  // Reload attendance table with the new filter
  // Also need to reload allUsers for the dropdowns
  loadAllUsers().then(() => {
    loadAttendanceForDate();
  });
}

// R8 — a user may not edit their OWN attendance from the web (must use the app for a
// genuine GPS check-in/out). The server is authoritative (403); this is the UX mirror.
function isOwnAttendanceRow(userId) {
  return window.currentUser && String(window.currentUser.id) === String(userId);
}
function blockSelfAttendanceEdit(userId) {
  if (isOwnAttendanceRow(userId)) {
    alert("You can't edit your own attendance from the web — check in or out from the app.");
    return true;
  }
  return false;
}

function populateUserDropdowns() {
  const select = document.getElementById('userSelect');
  // Exclude the logged-in user — they can't mark their own attendance here (R8).
  select.innerHTML = '<option value="">-- Select Employee --</option>' +
    allUsers.filter(u => !isOwnAttendanceRow(u.id)).map(u => `<option value="${u.id}" data-shift-start="${u.shift_start}" data-shift-end="${u.shift_end}" data-fullname="${u.fullname}" data-role="${u.role_name || 'Staff'}">${u.fullname || 'User #' + u.id} (${u.role_name || 'Staff'})</option>`).join('');
}

function selectUserFromDropdown() {
  const select = document.getElementById('userSelect');
  const selectedOption = select.options[select.selectedIndex];
  
  if (!selectedOption.value) {
    document.getElementById('userInfo').classList.add('hidden');
    selectedUserData = null;
    clearForm();
    return;
  }

  const userId = selectedOption.value;
  const userName = selectedOption.getAttribute('data-fullname');
  const shiftStart = selectedOption.getAttribute('data-shift-start');
  const shiftEnd = selectedOption.getAttribute('data-shift-end');
  const role = selectedOption.getAttribute('data-role');

  selectedUserData = { id: userId, name: userName, shiftStart, shiftEnd, role };

  // Show user info
  const info = document.getElementById('userInfo');
  document.getElementById('infoName').textContent = userName + ' (' + role + ')';
  document.getElementById('infoShift').textContent = `${shiftStart} - ${shiftEnd}`;
  info.classList.remove('hidden');
  
  // Load existing attendance for selected date
  loadExistingAttendance();
}

async function loadExistingAttendance() {
  if (!selectedUserData) return;
  
  const date = document.getElementById('attDate').value;
  if (!date) return;
  
  try {
    const res = await fetch(`/attendance/data?user_id=${selectedUserData.id}&date=${date}`, { 
      headers: { 'Accept': 'application/json' }
    });
    const json = await res.json();
    
    if (json.success && json.data && json.data.length > 0) {
      const record = json.data[0];
      // Trim DB seconds ("12:07:45" -> "12:07") — the minute-granular time input
      // otherwise shows a stuck seconds segment and the save is rejected as not H:i.
      document.getElementById('loginTime').value = String(record.login_time || '').slice(0, 5);
      document.getElementById('logoutTime').value = String(record.logout_time || '').slice(0, 5);
    } else {
      // No existing record, clear fields
      document.getElementById('loginTime').value = '';
      document.getElementById('logoutTime').value = '';
    }
  } catch(e) {
    console.error('Failed to load existing attendance', e);
  }
}

function clearForm() {
  document.getElementById('loginTime').value = '';
  document.getElementById('logoutTime').value = '';
}

async function saveAttendance() {
  if (!selectedUserData) {
    alert('Please select an employee');
    return;
  }

  const date = document.getElementById('attDate').value;
  const loginTime = document.getElementById('loginTime').value;
  const logoutTime = document.getElementById('logoutTime').value;

  if (!date) {
    alert('Please select a date');
    return;
  }

  if (!loginTime && !logoutTime) {
    alert('Please enter at least login time or logout time');
    return;
  }

  const payload = {
    user_id: parseInt(selectedUserData.id),
    attendance_date: date,
    login_time: loginTime || null,
    logout_time: logoutTime || null
  };

  try {
    const res = await fetch('/attendance', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify(payload)
    });

    const json = await res.json();
    if (json.success) {
      alert('✅ Attendance recorded successfully!');
      clearForm();
      toggleAddForm();
      loadAttendanceForDate();
    } else {
      alert('❌ Error: ' + (json.message || 'Failed to save attendance'));
    }
  } catch(e) {
    console.error('Error saving attendance', e);
    alert('❌ Error saving attendance');
  }
}

// Load attendance for the current date - Show ALL users
async function loadAttendanceForDate() {
  const date = document.getElementById('tableDate').value;
  const userFilter = document.getElementById('userFilter').value;
  const activeFilter = document.getElementById('activeFilter')?.value || 'active';
  
  try {
    // Fetch attendance data for the date (includes shift data from ShiftResolutionService)
    const attRes = await fetch(`/attendance/data?date=${date}&active_filter=${activeFilter}`);
    const attJson = await attRes.json();
    const attendanceData = attJson.success ? attJson.data : [];
    // Meter-flag thresholds (with sane defaults if the endpoint omits them).
    window.attConfig = attJson.config || {meter_gps_warn_km: 10, overnight_grace_km: 30};
    
    // Just use the attendance API data directly - it already has correct shifts!
    // The backend now returns ALL users (not just those with attendance/leave)
    allAttendanceData = attendanceData;
    
    // Apply user filter (using role_name from attendance API data)
    let filteredData = allAttendanceData;
    if (userFilter === 'riders') {
      filteredData = allAttendanceData.filter(u => {
        return u.role_name && u.role_name.toLowerCase().includes('rider');
      });
    } else if (userFilter === 'staff') {
      filteredData = allAttendanceData.filter(u => {
        return !u.role_name || !u.role_name.toLowerCase().includes('rider');
      });
    }
    
    renderAttendanceTable(filteredData);
    updateSummaryCards(filteredData);
    // Grace-breach alert reflects ALL riders (not the current table filter).
    renderGraceBreachBanner(allAttendanceData);
  } catch(e) {
    console.error('Error loading attendance', e);
    document.getElementById('attBody').innerHTML = '<tr><td colspan="4" class="px-4 py-8 text-center text-red-500 text-sm">Error loading data</td></tr>';
  }
}

// ===== Pending leave requests (rider-submitted) — approve/reject inline =====
function escLeave(s){ return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function leaveDateLabel(s){ try { return new Date(s+'T00:00:00').toLocaleDateString('en-GB',{weekday:'short',day:'numeric',month:'short'}); } catch(e){ return s; } }

async function loadPendingLeaves() {
  const card = document.getElementById('pendingLeavesCard');
  const body = document.getElementById('pendingLeavesBody');
  if (!card || !body) return;
  try {
    const res = await fetch('/attendance/pending-leaves', { headers: { 'Accept':'application/json' } });
    const j = await res.json();
    const reqs = (j.success && j.requests) ? j.requests : [];
    if (!reqs.length) { card.style.display = 'none'; return; }
    document.getElementById('pendingLeavesCount').textContent = reqs.length;
    body.innerHTML = reqs.map(r => {
      const range = r.start === r.end ? leaveDateLabel(r.start) : (leaveDateLabel(r.start) + ' → ' + leaveDateLabel(r.end));
      const stale = r.upcoming ? '' : '<span style="font-size:10px;color:#9CA3AF;">(past)</span>';
      return '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid #FDE68A;" data-leaverow="'+r.id+'">' +
        '<div style="flex:1;min-width:0;">' +
          '<div style="font-size:13px;font-weight:600;color:#111827;">'+escLeave(r.name)+' '+stale+'</div>' +
          '<div style="font-size:12px;color:#6B7280;">'+range+' · '+r.days+' day'+(r.days>1?'s':'')+' · '+escLeave(r.type)+'</div>' +
          (r.reason ? '<div style="font-size:11px;color:#9CA3AF;margin-top:1px;">'+escLeave(r.reason)+'</div>' : '') +
        '</div>' +
        '<button onclick="approveLeave('+r.id+', this)" style="background:#16A34A;color:#fff;border:none;border-radius:7px;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;">Approve</button>' +
        '<button onclick="rejectLeave('+r.id+', this)" style="background:#fff;color:#B91C1C;border:1px solid #FCA5A5;border-radius:7px;padding:6px 10px;font-size:12px;font-weight:600;cursor:pointer;">Reject</button>' +
      '</div>';
    }).join('');
    card.style.display = 'block';
  } catch(e) {
    console.error('Error loading pending leaves', e);
    card.style.display = 'none';
  }
}

async function approveLeave(id, btn) {
  btn.disabled = true; btn.textContent = '…';
  try {
    const res = await fetch('/attendance/leave-request/'+id+'/approve', {
      method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
    });
    const j = await res.json();
    if (!j.success) throw new Error(j.message || 'Failed');
    // Remove the row, refresh both the list and the day table (approved rider stops showing Absent).
    const row = document.querySelector('[data-leaverow="'+id+'"]'); if (row) row.remove();
    await loadPendingLeaves();
    if (typeof loadAttendanceForDate === 'function') loadAttendanceForDate();
  } catch(e) {
    alert('Could not approve: ' + (e.message || e));
    btn.disabled = false; btn.textContent = 'Approve';
  }
}

async function rejectLeave(id, btn) {
  const reason = prompt('Reason for rejecting this leave (optional):', '');
  if (reason === null) return; // cancelled
  btn.disabled = true; btn.textContent = '…';
  try {
    const res = await fetch('/attendance/leave-request/'+id+'/reject', {
      method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ reason })
    });
    const j = await res.json();
    if (!j.success) throw new Error(j.message || 'Failed');
    const row = document.querySelector('[data-leaverow="'+id+'"]'); if (row) row.remove();
    await loadPendingLeaves();
  } catch(e) {
    alert('Could not reject: ' + (e.message || e));
    btn.disabled = false; btn.textContent = 'Reject';
  }
}

function renderAttendanceTable(data) {
  const body = document.getElementById('attBody');

  renderMeterAttention(data);

  if (!data || data.length === 0) {
    body.innerHTML = '<tr><td colspan="4" class="px-4 py-8 text-center text-gray-500 text-sm">No attendance records found for this date</td></tr>';
    return;
  }

  // ── Row renderer (unchanged content) — used for all three groups below.
  const renderRow = (r) => {
    // Bypassed checkout (owner ruling Aug-8): the day's hours read to the COUNTED end
    // (last delivered order), not the bypass timestamp; nothing counted → no figure.
    const cc = r.checkout_counted;
    const hours = (cc && !cc.time) ? '-' : calculateHours(r.login_time, (cc && cc.time) ? cc.time : r.logout_time);
    // Prefer server-computed late/overtime (per-date + frozen-snapshot aware). Fall
    // back to local calc only if the server didn't send them (older cached responses).
    const fmtMins = (n) => { const h = Math.floor(n / 60), m = n % 60; return h > 0 ? `${h}h ${m}m` : `${m}m`; };
    // ⭐ 5-Oct-2026: `late_minutes` is what COUNTS (net of a waive, the payroll figure);
    //   the day is still a late day on the raw figure. Fully waived → "Late · waived".
    const lateRaw = (r.late_raw_minutes != null) ? Number(r.late_raw_minutes) : Number(r.late_minutes);
    const lateBy = (r.late_minutes != null)
      ? { isLate: lateRaw > 0,
          duration: Number(r.late_minutes) > 0 ? fmtMins(Number(r.late_minutes)) : (lateRaw > 0 ? 'waived' : '-') }
      : calculateLateBy(r.login_time, r.shift_start);
    const overtime = (r.overtime_minutes != null)
      ? { hasOvertime: r.overtime_minutes > 0, duration: r.overtime_minutes > 0 ? fmtMins(r.overtime_minutes) : '-' }
      : calculateOvertime(r.logout_time, r.shift_end, r.login_time);
    
    // Get location status
    const locationBadge = getLocationBadge(r);
    
    return `
      <tr class="hover:bg-gray-50" style="border-left:3px solid ${attStatusColor(r, lateBy)}" data-status="${getRowStatus(r, lateBy, overtime)}" data-location="${locationBadge.type}" data-bike="${Number(r.company_bike) === 1 ? '1' : '0'}">
        <td class="px-4 py-3 text-sm font-medium">
          <button
            onclick="openTodayDetail(${r.user_id}, \`${(r.fullname || '').replace(/`/g, '')}\`)"
            class="text-blue-600 hover:text-blue-800 hover:underline font-medium cursor-pointer text-left"
            title="This month's summary + day-by-day detail (click the counts inside to filter)"
          >
            ${r.fullname || '#' + r.user_id}
          </button>
          <div class="mt-1" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
            ${attStatusPill(r, lateBy, overtime)}
            <button type="button"
              onclick="openShiftChange({userId:${r.user_id}, userName:\`${(r.fullname||'').replace(/`/g,'')}\`, onSaved: loadAttendanceForDate})"
              title="${r.shift_start || '09:00'}${r.shift_end ? ' – ' + r.shift_end : ' onwards'} — click to change this rider's shift"
              style="background:none;border:none;padding:0;font-size:11px;color:#6B7280;cursor:pointer;white-space:nowrap;">
              · ${r.shift_name || 'Default Shift'} <span style="color:#D1D5DB;">✎</span>
            </button>
          </div>
          ${attInlineContext(r)}
        </td>
        <td class="px-4 py-3 text-sm" style="min-width:260px;">
          ${dayTimelineCell(r, lateBy, locationBadge)}
        </td>

        <!-- Day cell: meter (km + photos + gps audit) · hours, with ✎ meter + integrity flags -->
        <td class="px-4 py-3 text-sm" style="white-space:nowrap;">
          <div>${getDistanceBadge(r)}${hours !== '-' ? `<span style="color:#D1D5DB;margin:0 6px;">·</span><span style="color:#4B5563;">${hours}</span>` : ''}
            ${r.attendance_id ? `<button type="button" onclick="openMeterEdit(${r.attendance_id}, ${r.meter_start != null && r.meter_start !== '' ? Number(r.meter_start) : 'null'}, ${r.meter_end != null && r.meter_end !== '' ? Number(r.meter_end) : 'null'}, '${(r.fullname || '').replace(/'/g, "\\'").replace(/"/g, '&quot;')}', ${r.user_id})" title="Correct meter reading" style="margin-left:6px;font-size:11px;color:#2563EB;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:5px;padding:1px 6px;cursor:pointer;">✎ meter</button>` : ''}
          </div>
          ${getMeterFlags(r)}
        </td>

        <td class="px-4 py-3 text-sm">
          <div class="flex gap-2" style="position: relative; z-index: 5;">
            ${(!r.logout_time && !isOwnAttendanceRow(r.user_id)) ? `
              <button
                type="button"
                class="quick-add-btn px-2 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200 transition text-xs font-medium"
                data-user-id="${r.user_id}"
                data-user-name="${(r.fullname || '').replace(/"/g, '&quot;')}"
                title="${!r.login_time ? 'Add login time' : 'Add logout time'}"
                style="cursor: pointer;"
              >
                ➕
              </button>
            ` : ''}
            <button 
              type="button"
              class="manage-shift-btn px-2 py-1 bg-purple-100 text-purple-700 rounded hover:bg-purple-200 transition text-xs font-medium"
              data-user-id="${r.user_id}"
              data-user-name="${(r.fullname || '').replace(/"/g, '&quot;')}"
              title="Manage shift for ${(r.fullname || '').replace(/"/g, '&quot;')}"
              style="cursor: pointer;"
            >
              📅
            </button>
            ${!isOwnAttendanceRow(r.user_id) ? `
            <button
              type="button"
              class="quick-edit-btn px-2 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200 transition text-xs font-medium"
              data-user-id="${r.user_id}"
              data-user-name="${(r.fullname || '').replace(/"/g, '&quot;')}"
              data-login-time="${r.login_time || ''}"
              data-logout-time="${r.logout_time || ''}"
              data-attendance-date="${r.attendance_date}"
              title="Edit attendance"
              style="cursor: pointer;"
            >
              ✏️
            </button>
            ` : `<span class="px-2 py-1 text-xs text-gray-400" title="Edit your own attendance from the app">📱 app only</span>`}
            <button
              type="button"
              onclick="openHomeValve(${r.user_id}, '${(r.fullname || '').replace(/'/g, "\\'").replace(/"/g, '&quot;')}')"
              class="px-2 py-1 bg-amber-100 text-amber-700 rounded hover:bg-amber-200 transition text-xs font-medium"
              title="Rider bypasses — unlock checkout from anywhere (forgot to check out), unlock/enter the home meter"
              style="cursor: pointer;"
            >
              🔓
            </button>
            ${(!r.login_time || r.day_kind === 'not_needed') ? `
            <button type="button"
              onclick="toggleNotNeeded(${r.user_id}, '${r.attendance_date}', ${r.day_kind === 'not_needed' ? 'true' : 'false'})"
              title="${r.day_kind === 'not_needed' ? 'Marked not needed — click to undo' : 'Mark as not needed (paid, not counted absent)'}"
              style="padding:4px 8px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid ${r.day_kind === 'not_needed' ? '#c7d2fe' : '#e5e7eb'};background:${r.day_kind === 'not_needed' ? '#e0e7ff' : '#f9fafb'};color:${r.day_kind === 'not_needed' ? '#3730a3' : '#6b7280'};">🚫</button>
            ` : ''}
          </div>
        </td>
      </tr>
    `;
  };

  // ── Group the day so the manager's eye lands on who actually worked. Order preserved
  //    (controller sorts by name) within each group. A group header only appears when there
  //    is more than one group, so an all-present day stays a clean flat list.
  const onLeave = (r) => r.leave_request_id && ['approved', 'pending'].includes(String(r.leave_status || '').toLowerCase());
  const working = [], absent = [], resting = [];
  data.forEach(r => {
    if (r.login_time || r.logout_time) working.push(r);
    else if ((r.day_kind || 'working') === 'working' && !onLeave(r)) absent.push(r);
    else resting.push(r);
  });
  // Issues float to the top of "Working today": riders whose day isn't clean sort first, name
  // order preserved within each rank (Array.sort is stable). A fully green board = nothing on top.
  const cleanRank = (r) => (r.day_checks && r.day_checks.is_clean) ? 1 : 0;
  working.sort((a, b) => cleanRank(a) - cleanRank(b));
  const groups = [
    { label: 'Working today', rows: working },
    { label: 'Absent', rows: absent },
    { label: 'Not needed / off / leave', rows: resting },
  ].filter(g => g.rows.length);
  const showHeaders = groups.length > 1;
  const groupHdr = (label, n) =>
    `<tr class="att-group"><td colspan="4" style="background:#F3F4F6;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF;padding:6px 16px;">${label} · ${n}</td></tr>`;

  body.innerHTML = groups.map(g =>
    (showHeaders ? groupHdr(g.label, g.rows.length) : '') + g.rows.map(renderRow).join('')
  ).join('');
}

// Mark / unmark a rider as "not needed" for a day. Paid as present, never absent.
async function toggleNotNeeded(uid, date, isTagged) {
  // Absent rows have no attendance record, so r.attendance_date is missing → fall back to the
  // day the table is showing (the tag is always for the selected day). Fixes the
  // "The date field must be a valid date" error when tagging a no-show.
  if (!date || date === 'undefined' || date === 'null') {
    const td = document.getElementById('tableDate');
    date = td ? td.value : '';
  }
  if (!date) { alert('Please pick a valid date first.'); return; }
  const msg = isTagged
    ? 'Remove the "not needed" mark for this day?'
    : 'Mark this rider as NOT NEEDED for this day?\n\nIt will be treated as a normal paid day — not counted absent, no deduction.';
  if (!confirm(msg)) return;
  try {
    const res = await fetch('/attendance/toggle-day-tag', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ user_id: uid, date: date })
    });
    const j = await res.json();
    if (j.success) {
      if (typeof loadAttendanceForDate === 'function') loadAttendanceForDate();
    } else {
      alert(j.message || 'Could not update the day.');
    }
  } catch (e) { alert('Could not update the day.'); }
}

function getRowStatus(r, lateBy, overtime) {
  // Half day: he IS present (0.5 leave, late/OT suppressed server-side) — never 'leave'.
  if (r.is_half_day && r.login_time) return 'present';
  const onLeave = r.leave_request_id && ['approved','pending'].includes(String(r.leave_status || '').toLowerCase());
  if (onLeave) return 'leave';
  // No login: only a genuine working day is "absent"; off/holiday/not-joined are
  // their own status so the Absent filter doesn't sweep them in.
  if (!r.login_time) return ((r.day_kind || 'working') === 'working') ? 'absent' : (r.day_kind || 'off');
  if (overtime.hasOvertime) return 'overtime';
  if (lateBy.isLate) return 'late';
  return 'present';
}

// Status chip under the employee name — folds in Late duration + an Overtime badge
// (replaces the old separate Late By / Overtime columns).
function attStatusPill(r, lateBy, overtime) {
  const onLeave = r.leave_request_id && ['approved','pending'].includes(String(r.leave_status || '').toLowerCase());
  const kind = r.day_kind || 'working';
  let label, bg, fg;
  // Half day beats the leave pill: he checked in — present with a ½-day mark, never "On leave".
  if (r.is_half_day && r.login_time) { label = '🌗 ½ day';     bg = '#EDE9FE'; fg = '#6D28D9'; }
  else if (onLeave)       { label = 'On leave';                bg = '#E6ECFD'; fg = '#1D4ED8'; }
  // A no-login day only counts as Absent on a genuine working day. Holiday / weekly
  // off / before-hire-date show a neutral gray chip, never red.
  else if (!r.login_time && kind === 'holiday')     { label = 'Holiday';    bg = '#EEF1F5'; fg = '#5B6B84'; }
  else if (!r.login_time && kind === 'off')         { label = 'Off day';    bg = '#EEF1F5'; fg = '#5B6B84'; }
  else if (!r.login_time && kind === 'not_joined')  { label = 'Not joined'; bg = '#EEF1F5'; fg = '#94A3B8'; }
  // A manager-tagged "not needed" day with no login — paid as present, never absent.
  // (If the rider logged in anyway, normal On-time/Late below applies.)
  else if (!r.login_time && kind === 'not_needed')  { label = 'Not needed'; bg = '#E6ECFD'; fg = '#3730A3'; }
  else if (!r.login_time) { label = 'Absent';                 bg = '#FDE7E7'; fg = '#B42318'; }
  else if (lateBy.isLate) { label = 'Late ' + lateBy.duration; bg = '#FBEEDC'; fg = '#B45309'; }
  else                    { label = 'On time';                bg = '#E6F3EB'; fg = '#15803D'; }
  let html = `<span style="display:inline-block; font-size:11px; font-weight:600; padding:1px 8px; border-radius:20px; background:${bg}; color:${fg};">${label}</span>`;
  if (overtime && overtime.hasOvertime) {
    html += ` <span style="display:inline-block; font-size:11px; font-weight:600; padding:1px 8px; border-radius:20px; background:#E6F3EB; color:#15803D;">+OT ${overtime.duration}</span>`;
  }
  return html;
}

// Small inline context under the status pill:
//   • month-to-date lateness (amber) — always shown when > 0, so a manager sees the
//     accumulating pattern without opening the Month tab.
//   • "Nth absence this year" (red) — only when the rider is absent today, so an
//     unexplained no-show is instantly weighed against their yearly record.
function attInlineContext(r) {
  const bits = [];
  /* 🔧 WORKSHOP ERRAND (Sep-2026). Shown BESIDE the day's status, never instead of it —
     a workshop day is a NORMAL PAID WORKING DAY (owner ruling), so this changes nothing
     about pay, lateness or absence. It is here so a manager reading attendance knows why
     a rider is not where he usually is. ⏳ = he has not confirmed it yet.
     Same server map the shift planner paints from, so the two screens always agree. */
  if (r.workshop) {
    const w = r.workshop;
    const ok = w.accepted;
    bits.push(`<span title="${(w.vehicle_name || 'Bike')} to the workshop${w.workshop ? ' — ' + w.workshop : ''}${ok ? ' · confirmed by the rider' : ' · NOT confirmed yet'}. He still works and is paid as normal." style="display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;color:#92400E;background:#FEF3C7;border:1px solid #FCD34D;border-radius:5px;padding:1px 6px;">🔧 workshop${w.time ? ' ' + w.time : ''} ${ok ? '✓' : '⏳'}</span>`);
  }
  // Leave detail (the old Leave column, folded in): type + approval state.
  if (r.leave_request_id) {
    const st = String(r.leave_status || '').toLowerCase();
    const isHalf = String(r.leave_type_from_req || '').toLowerCase() === 'half_day';
    const [bg, fg, bd] = isHalf             ? ['#EDE9FE', '#6D28D9', '#DDD6FE']
                       : st === 'approved' ? ['#DCFCE7', '#15803D', '#86EFAC']
                       : st === 'pending'  ? ['#FEF9C3', '#854D0E', '#FDE047']
                       :                     ['#FEE2E2', '#B42318', '#FCA5A5'];
    const typeLabel = isHalf ? '🌗 ½ day (0.5)' : `🏖 ${r.leave_type_from_req || 'Leave'}`;
    bits.push(`<span data-leave-chip="1" title="Leave request ${st} — click to view / undo" onclick="event.stopPropagation(); openLeaveActions(${r.user_id}, '${(r.fullname || '').replace(/'/g, "\\'")}')" style="cursor:pointer;display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:600;color:${fg};background:${bg};border:1px solid ${bd};border-radius:5px;padding:1px 6px;">${typeLabel} · ${st}</span>`);
  }
  const mlate = Number(r.month_late_minutes) || 0;
  const mwaived = Number(r.month_late_waived_minutes) || 0;   // Oct-2026: forgiven this month
  if (mlate > 0 || mwaived > 0) {
    const fm = (x) => { const h = Math.floor(x / 60), m = x % 60; return h > 0 ? `${h}h ${m}m` : `${m}m`; };
    // Month context is background info, not a today-alert — quiet gray text, no chip. Colour is
    // reserved for things that need attention now (leave state, a year-absence threshold below).
    bits.push(`<span title="Late so far this month that still counts${mwaived > 0 ? ' (after ' + fm(mwaived) + ' waived)' : ''}" style="font-size:11px;color:#9CA3AF;">⏰ ${fm(mlate)} late this mo${mwaived > 0 ? ' · ' + fm(mwaived) + ' waived' : ''}</span>`);
  }
  // ⭐ Sep-6 2026 — has TODAY's lateness / overtime already been looked at? A quiet marker,
  // not an alarm: the bulb is what asks for the work, this only says where it stands so the
  // Today board tells the same story as Payroll. Only ever shown once a verdict exists.
  ['late', 'overtime'].forEach((kind) => {
    const rv = kind === 'late' ? r.late_review : r.overtime_review;
    if (!rv) return;
    const label = rv.verdict === 'verified'
      ? (kind === 'late' ? 'late checked' : 'overtime checked')
      : (kind === 'late' ? `${rv.waived}m waived` : (rv.verdict === 'waived' ? 'not overtime' : 'overtime adjusted'));
    const who = rv.by ? ` by ${String(rv.by).replace(/</g, '&lt;')}` : '';
    const why = rv.reason ? ` — “${String(rv.reason).replace(/</g, '&lt;')}”` : '';
    bits.push(`<span title="Checked${who}${why}" style="font-size:11px;color:#047857;font-weight:600;">✔ ${label}</span>`);
  });
  const ya = r.year_absent_days;
  if (ya != null && Number(ya) > 0) {
    const n = Number(ya);
    const ord = (n % 10 === 1 && n % 100 !== 11) ? 'st' : (n % 10 === 2 && n % 100 !== 12) ? 'nd' : (n % 10 === 3 && n % 100 !== 13) ? 'rd' : 'th';
    bits.push(`<span title="Absent working-days so far this year cycle (including today)" style="display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;color:#B42318;background:#FDE7E7;border:1px solid #FCA5A5;border-radius:5px;padding:1px 6px;">📅 ${n}${ord} absence this yr</span>`);
  }
  if (!bits.length) return '';
  return `<div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">${bits.join('')}</div>`;
}

// Meter-photo proof icons for the distance cell. A rider who logged a meter reading
// should also have snapped the odometer: a camera with photo on file is CLICKABLE
// (opens the photo via the shared viewMeterPicturePath viewer); a greyed camera =
// reading present but NO photo (can't be verified).
function getMeterPhotoIcons(r) {
  const icons = [];
  const path = v => (v && v !== '-') ? String(v).replace(/'/g, "\\'") : null;
  const cam = (p, title) => p
    ? `<button type="button" onclick="viewMeterPicturePath('${p}')" title="${title}: photo attached — click to view" style="background:none;border:none;padding:0;cursor:pointer;font-size:12px;line-height:1;">📷</button>`
    : `<span title="${title}: no photo" style="font-size:12px;filter:grayscale(1);opacity:0.4;line-height:1;">📷</span>`;
  if (r.meter_start != null && r.meter_start !== '') icons.push(cam(path(r.picture_start), 'Start meter'));
  if (r.meter_end != null && r.meter_end !== '')   icons.push(cam(path(r.picture_end), 'End meter'));
  if (!icons.length) return '';
  return `<span style="display:inline-flex;gap:3px;margin-left:5px;vertical-align:middle;">${icons.join('')}</span>`;
}

// Left-edge accent colour per row status (scannable at a glance).
function attStatusColor(r, lateBy) {
  const onLeave = r.leave_request_id && ['approved','pending'].includes(String(r.leave_status || '').toLowerCase());
  const kind = r.day_kind || 'working';
  if (onLeave) return '#1D4ED8';
  // Genuine absence (a working day with no login) stands out red; off/holiday/
  // not-joined stay neutral gray.
  if (!r.login_time) return (kind === 'working') ? '#EF4444' : '#CBD5E1';
  if (lateBy.isLate) return '#F59E0B';
  return '#22C55E';
}

/**
 * Get location badge HTML based on attendance location data
 */
function getLocationBadge(record) {
  // No check-in = no location data
  if (!record.login_time) {
    return {
      type: 'no_location',
      html: '<span class="text-gray-400 text-xs">-</span>'
    };
  }

  // Has location data
  if (record.checkin_latitude && record.checkin_longitude) {
    const mapsUrl = `https://www.google.com/maps?q=${record.checkin_latitude},${record.checkin_longitude}`;
    
    // Name of the base the distance was measured against (the SHIFT location for
    // the selected date, recomputed server-side) — "7.9 km from LaCarne" tells the
    // manager which pin the number is relative to; a bare "away" doesn't.
    const escLoc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const baseName = record.assigned_office_location ? escLoc(record.assigned_office_location) : null;

    // Compact dot-pill (inline-styled, purge-safe). A small coloured dot replaces the
    // oversized tick/pin SVGs; the whole pill links to Google Maps.
    const pill = (bg, fg, dot, text) => `
      <a href="${mapsUrl}" target="_blank" title="Click to view on Google Maps"
         style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;background:${bg};color:${fg};text-decoration:none;white-space:nowrap;">
        <span style="width:7px;height:7px;border-radius:50%;background:${dot};display:inline-block;flex:none;"></span>${text}
      </a>`;

    if (record.is_remote_checkin == 1) {
      // Remote check-in (outside the base location's radius)
      const distanceKm = (record.checkin_distance_from_base / 1000).toFixed(1);
      return {
        type: 'remote',
        html: pill('#FEE2E2', '#B42318', '#EF4444', `${distanceKm} km ${baseName ? 'from ' + baseName : 'away'}`)
      };
    } else {
      // Onsite check-in (within the base location's radius)
      const distanceText = record.checkin_distance_from_base
        ? (record.checkin_distance_from_base < 1000
            ? `${Math.round(record.checkin_distance_from_base)}m`
            : `${(record.checkin_distance_from_base / 1000).toFixed(1)}km`)
        : '';
      return {
        type: 'onsite',
        html: pill('#DCFCE7', '#15803D', '#22C55E', `At ${baseName || 'office'}${distanceText ? ` · ${distanceText}` : ''}`)
      };
    }
  }

  // Check-in without location (GPS failed or denied)
  return {
    type: 'no_location',
    html: `<span title="Location not captured" style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;background:#F3F4F6;color:#6B7280;white-space:nowrap;"><span style="width:7px;height:7px;border-radius:50%;background:#9CA3AF;display:inline-block;flex:none;"></span>No location</span>`
  };
}

/**
 * Meter-integrity flags shown under the distance detail:
 *   ⛽ no meter        — a rider finished the day (checked out) but logged no meter reading
 *   ⚠ N km off         — meter vs tracked (road/GPS) distance differ beyond the threshold
 *                        (accuracy warning only, never blocks anything)
 *   🏍 +N km overnight — company-bike rider whose start meter exceeds yesterday's end meter
 *                        beyond the grace → the bike was used off company hours
 * Thresholds come from window.attConfig (backend t_fin_config, defaults 10 / 25 km).
 */
// ── Tabs: Today / Month / Calendar ───────────────────────────────────────────
let monthData = [];
function switchAttTab(tab) {
  ['today','month','calendar'].forEach(t => {
    const cap = t.charAt(0).toUpperCase() + t.slice(1);
    const panel = document.getElementById('tab' + cap);
    const btn = document.getElementById('tabBtn' + cap);
    if (panel) panel.classList.toggle('hidden', t !== tab);
    if (btn) {
      const active = (t === tab);
      btn.classList.toggle('bg-white', active);
      btn.classList.toggle('text-blue-700', active);
      btn.classList.toggle('shadow-sm', active);
      btn.classList.toggle('text-gray-600', !active);
    }
  });
  if (tab === 'month') loadMonthTab();
  if (tab === 'calendar') loadCalendarTab();
}

// ---- Month tab ----
function currentMonthValue() {
  const el = document.getElementById('monthPicker');
  if (el && el.value) return el.value;
  const d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
}
function monthStep(delta) {
  const parts = currentMonthValue().split('-');
  const d = new Date(Number(parts[0]), Number(parts[1]) - 1 + delta, 1);
  document.getElementById('monthPicker').value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
  loadMonthTab();
}
async function loadMonthTab() {
  const monthEl = document.getElementById('monthPicker');
  if (monthEl && !monthEl.value) monthEl.value = currentMonthValue();
  const body = document.getElementById('monthBody');
  body.innerHTML = '<tr><td colspan="11" class="px-4 py-8 text-center text-gray-400 text-sm">Loading…</td></tr>';
  try {
    const res = await fetch('/attendance/monthly-report?month=' + currentMonthValue());
    const json = await res.json();
    monthData = json.success ? (json.data || []) : [];
    renderMonthBody(monthData);
  } catch (e) {
    body.innerHTML = '<tr><td colspan="11" class="px-4 py-8 text-center text-red-500 text-sm">Failed to load</td></tr>';
  }
  // Awaited so a caller that reloads the month (after deciding an action) can rely on the
  // banner data being fresh; it runs after the table is already on screen either way.
  await loadPayrollLeaveBanner();
}

// ---- Payroll leave actions on the Attendance month ----
// The bonus/penalty leaves this month earns or costs. Same server payload the Payroll
// screen renders, so the two can't disagree, and the same `manage_payroll` authority:
// a viewer without it simply never sees the banner (the endpoint refuses).
let PAYROLL_LEAVE_ACT = null;

async function loadPayrollLeaveBanner() {
  const el = document.getElementById('leaveActBanner');
  if (!el) return;
  el.style.display = 'none';
  try {
    const res = await fetch('/hr/payroll/leave-actions?month=' + currentMonthValue(), { headers: { 'Accept': 'application/json' } });
    if (!res.ok) return;                       // 403 = no payroll access → stay hidden
    const j = await res.json();
    if (!j.success || !(j.rows || []).length) return;
    PAYROLL_LEAVE_ACT = j;
    const s = j.summary || {};
    const pending = Number(s.pending_count || 0);
    const open = !j.closed;
    const bits = [];
    if (s.give_pending) bits.push(`<b style="color:#047857;">+${s.give_pending}</b> bonus to give`);
    if (s.deduct_pending) bits.push(`<b style="color:#B45309;">−${s.deduct_pending}</b> to deduct`);
    if (s.given) bits.push(`<b style="color:#047857;">+${s.given}</b> given`);
    if (s.deducted) bits.push(`<b style="color:#B45309;">−${s.deducted}</b> deducted`);
    if (s.waived) bits.push(`${s.waived} waived`);

    // Amber only when something is actually actionable; a running month is a preview and a
    // settled month is history — neither is a to-do.
    const amber = pending > 0 && !open;
    const bg = amber ? '#FFFBEB' : (open ? '#F9FAFB' : '#F0FDF4');
    const bd = amber ? '#FCD34D' : (open ? '#E5E7EB' : '#A7F3D0');
    const fg = amber ? '#92400E' : (open ? '#6B7280' : '#047857');
    const lead = open
      ? `Leave actions for ${j.month_label} (still counting)`
      : (amber ? `⏳ Payroll leave actions for ${j.month_label} are due` : `✓ ${j.month_label} leave actions settled`);
    el.innerHTML = `<div style="background:${bg};border:1px solid ${bd};border-radius:10px;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <div style="font-size:13px;color:${fg};">${lead}${bits.length ? ' — ' + bits.join(' · ') : ''}${amber ? ` · <b>${pending} pending</b>` : ''}</div>
      <button type="button" onclick="openPayrollLeaveActions()" style="background:#fff;border:1px solid ${bd};color:${fg};border-radius:8px;padding:5px 14px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;">${amber ? 'Review' : 'View'} ›</button>
    </div>`;
    el.style.display = '';
  } catch (e) { /* banner is additive — never break the month tab */ }
}

// NOTE the name: a DIFFERENT openLeaveActions(userId, name) already exists further down for
// the Today-row leave chip. Function declarations hoist and the last one wins, so a shared
// name here would silently hijack that one (or be hijacked by it) with no console error.
function openPayrollLeaveActions() {
  if (!PAYROLL_LEAVE_ACT) return;
  document.getElementById('laTitle').textContent = 'Leave actions — ' + PAYROLL_LEAVE_ACT.month_label;
  document.getElementById('laSub').textContent = PAYROLL_LEAVE_ACT.closed
    ? 'Bonus leaves earned and leaves lost to lateness. Money is not changed here.'
    : PAYROLL_LEAVE_ACT.month_label + ' is still running — these are previews and can be decided once it ends.';
  document.getElementById('leaveActModal').style.display = 'flex';
  renderPayrollLeaveActions();
}
function closePayrollLeaveActions() { document.getElementById('leaveActModal').style.display = 'none'; }

// ⭐⭐ Sep-6 2026 — this used to be a HAND-COPIED clone of the payroll panel and it had
//   drifted badly: no absence branch at all, so an absence rendered as "Deduct 2 days
//   absent / Keep leave", posted decision:'apply', and the service refused it every time
//   ("Choose deduct, park or excuse") — there was no way to succeed from this screen. A
//   parked month rendered as "✓ −2 leaves deducted", the opposite of what happened.
//   It now draws the SAME rows as payroll, from partials/leave-actions-panel.
function renderPayrollLeaveActions() {
  const j = PAYROLL_LEAVE_ACT, s = j.summary || {}, open = !j.closed;

  const cut = s.late_cut_count
    ? `<div style="font-size:11.5px;color:#B45309;background:#FFFBEB;border-radius:8px;padding:8px 10px;margin-bottom:10px;">⚠ ${s.late_cut_count} employee${s.late_cut_count > 1 ? 's have' : ' has'} a late <b>salary cut</b> of Rs ${Math.round(s.late_cut_total).toLocaleString('en-PK')} this month (too late for a leave penalty). That is money — it is deducted on the Payroll screen when the salary is paid.</div>` : '';
  const all = (!open && s.pending_count > 0)
    ? `<div style="margin-bottom:10px;"><button type="button" id="laApplyAll"
         style="background:#ECFDF5;border:1px solid #A7F3D0;color:#047857;border-radius:6px;padding:5px 13px;font-size:12px;font-weight:700;cursor:pointer;">Apply all ${s.pending_count} recommended</button>
         <span style="font-size:11px;color:#9CA3AF;margin-left:8px;">gives the bonuses and takes the penalties</span></div>` : '';

  const items = [];
  (j.rows || []).forEach(r => (r.actions || []).forEach(a => {
    items.push(Object.assign({ user_id: r.user_id, fullname: r.fullname, paid: r.paid }, a));
  }));

  NFLeaveActions.render(document.getElementById('laBody'), items, {
    header: cut + all,
    monthOpen: open,
    monthLabel: j.month_label,
    onDecide: (item, choice) => decidePayrollLeaveAction(item.user_id, item.kind, choice),
    onDrill: (item) => showDateBreakdown(item.user_id, item.fullname, item.drill),
    afterPaint: () => {
      const b = document.getElementById('laApplyAll');
      if (b) b.onclick = () => applyAllPayrollLeaveActions();
    }
  });
}

async function decidePayrollLeaveAction(userId, kind, decision) {
  document.querySelectorAll('#laBody button').forEach(b => b.disabled = true);
  try {
    const res = await fetch('/hr/payroll/leave-actions/decide', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ user_id: userId, month: currentMonthValue(), kind: kind, decision: decision })
    });
    const j = await res.json();
    if (!j.success) throw new Error(j.message || 'Failed');
    await refreshPayrollLeaveActions();
  } catch (e) {
    alert('Could not save that: ' + (e.message || e));
    renderPayrollLeaveActions();
  }
}

async function applyAllPayrollLeaveActions() {
  const s = (PAYROLL_LEAVE_ACT && PAYROLL_LEAVE_ACT.summary) || {};
  if (!confirm(`Apply all ${s.pending_count} recommended leave actions for ${PAYROLL_LEAVE_ACT.month_label}?\n\nBonus leaves are given and late penalties are deducted. Anything already decided is left alone.`)) return;
  document.querySelectorAll('#laBody button').forEach(b => b.disabled = true);
  try {
    const res = await fetch('/hr/payroll/leave-actions/apply-all', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ month: currentMonthValue() })
    });
    const j = await res.json();
    if (!j.success) throw new Error(j.message || 'Failed');
    await refreshPayrollLeaveActions();
    alert(j.message || 'Done.');
  } catch (e) {
    alert('Could not apply: ' + (e.message || e));
    renderPayrollLeaveActions();
  }
}

// Re-read from the server after a decision: the banner, the open modal and the month's
// leave balances all move together.
async function refreshPayrollLeaveActions() {
  await loadMonthTab();   // reloads the table (leave balances moved) and the banner data
  if (document.getElementById('leaveActModal').style.display === 'flex') { renderPayrollLeaveActions(); }
}
function fmtMinsShort(n) { n = Number(n) || 0; const h = Math.floor(n / 60), m = n % 60; return h > 0 ? `${h}h ${m}m` : `${m}m`; }
function fmtCycleShort(d) {
  if (!d) return '';
  const p = String(d).split('-'); if (p.length !== 3) return d;
  const m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  return m[parseInt(p[1],10)-1] + " '" + p[0].slice(2);
}
function renderMonthBody(data) {
  const body = document.getElementById('monthBody');
  const lbl = document.getElementById('monthCycleLabel');
  if (lbl) {
    const c0 = data[0] || {};
    lbl.textContent = (c0.cycle_start && c0.cycle_end) ? '(' + fmtCycleShort(c0.cycle_start) + ' → ' + fmtCycleShort(c0.cycle_end) + ')' : '';
  }
  if (!data.length) { body.innerHTML = '<tr><td colspan="11" class="px-4 py-8 text-center text-gray-400 text-sm">No data for this month</td></tr>'; return; }
  const sorted = [...data].sort((a, b) => String(a.fullname || '').localeCompare(String(b.fullname || '')));
  // A clickable count → opens the exact-dates drill-down. Zero shows a muted dash so the
  // eye skips it. stopPropagation keeps the row's own "open 30-day detail" from also firing.
  const numCell = (val, color, uid, nm, type) => {
    const n = Number(val) || 0;
    if (n <= 0) return `<td class="px-4 py-3 text-sm" style="text-align:center;color:#D1D5DB;">–</td>`;
    return `<td class="px-4 py-3 text-sm" style="text-align:center;">
      <button type="button" onclick="event.stopPropagation(); showDateBreakdown(${uid}, '${nm}', '${type}')"
        title="Click to see the exact dates"
        style="background:none;border:none;cursor:pointer;font-weight:700;color:${color};text-decoration:underline;text-decoration-style:dotted;text-underline-offset:2px;font-size:13px;">${n}</button>
    </td>`;
  };
  // W3 — a count that opens the FULL month detail popup pre-filtered to that category
  // (all context: orders, meters, times) instead of a bare date list.
  const filterCell = (val, color, uid, nm, filter) => {
    const n = Number(val) || 0;
    if (n <= 0) return `<td class="px-4 py-3 text-sm" style="text-align:center;color:#D1D5DB;">–</td>`;
    return `<td class="px-4 py-3 text-sm" style="text-align:center;">
      <button type="button" onclick="event.stopPropagation(); openMonthDetail(${uid}, '${nm}', '${filter}')"
        title="Open this month's detail, filtered to these days"
        style="background:none;border:none;cursor:pointer;font-weight:700;color:${color};text-decoration:underline;text-decoration-style:dotted;text-underline-offset:2px;font-size:13px;">${n}</button>
    </td>`;
  };
  // ⭐ Sep-6 2026 — the day-review standing, in the SAME words the Payroll screen uses.
  // Same rule too: silent when the feature is off, before the start date, or when the month
  // had nothing to review. Clicking opens the same drill, which is where a day is judged.
  const attReviewChip = (u, kind, uid, nm) => {
    const dr = u.day_review;
    if (!dr || !dr.enabled) return '';
    const s = dr[kind];
    if (!s || !s.days) return '';
    const drill = kind === 'late' ? 'month_late' : 'month_overtime';
    const open = `<button type="button" onclick="event.stopPropagation(); showDateBreakdown(${uid}, '${nm}', '${drill}')" style="background:none;border:none;padding:0;cursor:pointer;font-size:9.5px;font-weight:700;text-decoration:underline;text-decoration-style:dotted;`;
    if (s.stale) {
      return `${open}color:#B45309;">🔁 ${s.stale} changed after checking ›</button>`;
    }
    if (s.pending > 0) {
      return `<span style="display:block;">${open}color:#B45309;">⚠ ${s.reviewed} of ${s.days} checked ›</button></span>`;
    }
    const extra = kind === 'late'
      ? (s.waived_minutes > 0 ? ` · ${fmtMinsShort(s.waived_minutes)} waived` : '')
      : (s.changed > 0 ? ` · ${s.changed} adjusted` : '');
    return `<span style="display:block;">${open}color:#047857;">✔ all ${s.days} checked${extra} ›</button></span>`;
  };

  // What a manager forgave, said out loud — otherwise the column simply shows a smaller
  // number than the days add up to and it reads as the engine changing its mind.
  const lateWaivedNote = (u) => {
    const w = Number(u.late_waived_minutes) || 0;
    if (w <= 0) return '';
    return `<span style="display:block;font-size:9.5px;color:#6B7280;">${fmtMinsShort(Number(u.late_raw_minutes) || 0)} · ${fmtMinsShort(w)} waived</span>`;
  };

  body.innerHTML = sorted.map(u => {
    const late = Number(u.total_late_minutes) || 0;
    const lateStyle = late > 300 ? 'background:#FEE2E2;color:#B91C1C;' : (late > 0 ? 'background:#FEF3C7;color:#92400E;' : 'background:#F3F4F6;color:#9CA3AF;');
    const uid = u.user_id;
    const nm = String(u.fullname || '').replace(/'/g, "\\'");
    // Overtime is stated with what it BUYS: the hours, and the whole bonus leave days those
    // hours have already earned (same divisor payroll grants on). Hours alone made a manager
    // do the division in his head — and made this column look unrelated to the popup's.
    const ot = Number(u.overtime_target_minutes) || 0;
    const otBonus = Number(u.overtime_bonus_leaves) || 0;
    const otPer = Number(u.overtime_minutes_per_bonus) || 540;
    const otTitle = `${fmtMinsShort(ot)} beyond the daily target. ${fmtMinsShort(otPer)} = 1 bonus leave day, so this earns ${otBonus}. Click to see the days.`;
    const otCell = ot > 0
      ? `<td class="px-4 py-3 text-center"><button type="button" onclick="event.stopPropagation(); showDateBreakdown(${uid}, '${nm}', 'month_overtime')" title="${otTitle}" style="background:none;border:none;cursor:pointer;font-weight:700;color:#047857;text-decoration:underline;text-decoration-style:dotted;text-underline-offset:2px;font-size:13px;line-height:1.25;">${fmtMinsShort(ot)}${otBonus > 0 ? `<span style="display:block;font-size:9.5px;font-weight:700;color:#6d28d9;text-decoration:none;">= ${otBonus} bonus day${otBonus === 1 ? '' : 's'}</span>` : ''}</button>${attReviewChip(u, 'overtime', uid, nm)}</td>`
      : `<td class="px-4 py-3 text-sm" style="text-align:center;color:#D1D5DB;">–</td>`;
    const flagsTd = flagsCell(u, uid, nm);
    const meterTd = meterCell(u, uid, nm);
    const offTd = offDutyCell(u, uid, nm);
    return `<tr class="hover:bg-gray-50 cursor-pointer" onclick="openMonthDetail(${uid}, '${nm}')">
      <td class="px-4 py-3 text-sm" style="font-weight:600;color:#1F2937;">${u.fullname || ''}</td>
      ${filterCell(u.present_days, '#374151', uid, nm, 'present')}
      ${filterCell(u.absent_days, '#DC2626', uid, nm, 'absent')}
      ${filterCell(u.leave_days, '#2563EB', uid, nm, 'on_leave')}
      <td class="px-4 py-3 text-center">${late > 0
        ? `<button type="button" onclick="event.stopPropagation(); openMonthDetail(${uid}, '${nm}', 'late')" title="Open this month's detail, filtered to late days" style="display:inline-block;padding:2px 8px;border-radius:5px;font-size:12px;font-weight:600;border:none;cursor:pointer;${lateStyle}">${fmtMinsShort(late)}</button>`
        : `<span style="display:inline-block;padding:2px 8px;border-radius:5px;font-size:12px;font-weight:600;${lateStyle}">${fmtMinsShort(late)}</span>`}${lateWaivedNote(u)}${attReviewChip(u, 'late', uid, nm)}</td>
      ${otCell}
      ${flagsTd}
      ${meterTd}
      ${offTd}
      ${leaveBalCell(u, uid, nm)}
      ${numCell(u.absent_days_year, '#B91C1C', uid, nm, 'year_absent')}
    </tr>`;
  }).join('');
}

// ONE "Flags" cell instead of three columns (In-flags / Out-flags / Office CO). The three are
// the same kind of thing — a day that needs a manager's eye — so they read as one group of
// chips, each still its own drill. Only non-zero chips are drawn; nothing to answer for = "–".
//   🌅 morning (check-IN)  ·  🌙 evening (check-OUT)  ·  🏢 checked out at the office
function flagsCell(u, uid, nm) {
  const chips = [
    { n: Number(u.checkin_violation_days) || 0,  icon: '🌅', bg: '#CFFAFE', fg: '#0E7490',
      click: `showViolationDetail(${uid}, '${nm}', 'in')`,
      title: 'Morning flags — arrived after the ride-in deadline, no start meter from home, or a morning meter gap. Click for the days.' },
    { n: Number(u.checkout_violation_days) || 0, icon: '🌙', bg: '#FEF3C7', fg: '#B45309',
      click: `showViolationDetail(${uid}, '${nm}', 'out')`,
      title: 'Evening flags — reached home late, meter locked, or a manager had to unlock it. Click for the days.' },
    { n: Number(u.office_checkout_days) || 0,    icon: '🏢', bg: '#F3E8D6', fg: '#92400E',
      click: `showDateBreakdown(${uid}, '${nm}', 'month_office_checkout')`,
      title: 'Delivered but checked out at the office (came back). Click for the days.' },
  ].filter(c => c.n > 0);
  if (!chips.length) return `<td class="px-4 py-3 text-sm" style="text-align:center;color:#D1D5DB;">–</td>`;
  const html = chips.map(c =>
    `<button type="button" onclick="event.stopPropagation(); ${c.click}" title="${c.title}"
      style="display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:5px;font-size:11.5px;font-weight:700;border:none;cursor:pointer;background:${c.bg};color:${c.fg};line-height:1.5;">${c.icon} ${c.n}</button>`).join('');
  return `<td class="px-4 py-3"><div style="display:flex;flex-wrap:wrap;gap:4px;justify-content:center;">${html}</div></td>`;
}

// "Meter" cell — finished working days missing a meter reading. The count comes from the SAME
// server rule as the daily ⛽ tick (DayChecksService::meterMissReason), which only judges days
// with BOTH a check-in and a check-out — so an absence, a leave day, today's running shift and
// any future date are structurally impossible here, not filtered out afterwards.
function meterCell(u, uid, nm) {
  const n = Number(u.meter_missed_days) || 0;
  if (n <= 0) return `<td class="px-4 py-3 text-sm" style="text-align:center;color:#D1D5DB;">–</td>`;
  return `<td class="px-4 py-3 text-sm" style="text-align:center;">
    <button type="button" onclick="event.stopPropagation(); showDateBreakdown(${uid}, '${nm}', 'month_meter_missed')"
      title="Days he worked a full day but a meter reading is missing — click to see which, and whether it was the start or the closing one"
      style="display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:5px;font-size:11.5px;font-weight:700;background:#FFEDD5;color:#C2410C;border:none;cursor:pointer;line-height:1.5;">⛽ ${n}</button>
  </td>`;
}

// ⭐ Sep-30 — "Off-duty km": the Bikes page's own number (server: MachineAttribution), so the
// two screens cannot disagree. null = no company machine this month → "–". 0 is shown as 0:
// he had a company machine and it did not move off duty, which is worth seeing.
function offDutyCell(u, uid, nm) {
  if (u.offduty_km == null) return `<td class="px-4 py-3 text-sm" style="text-align:center;color:#D1D5DB;">–</td>`;
  const km = Number(u.offduty_km) || 0;
  const nights = (u.offduty_nights || []).length;
  if (km <= 0) return `<td class="px-4 py-3 text-sm" style="text-align:center;color:#9CA3AF;">0</td>`;
  return `<td class="px-4 py-3 text-sm" style="text-align:center;">
    <button type="button" onclick="event.stopPropagation(); showOffDutyNights(${uid}, '${nm}')"
      title="${nights} night${nights === 1 ? '' : 's'} — click for each one"
      style="background:none;border:none;cursor:pointer;font-weight:700;color:#B45309;text-decoration:underline;text-decoration-style:dotted;text-underline-offset:2px;font-size:13px;">${km.toLocaleString()}</button>
  </td>`;
}

// Where the morning reading that ended the stretch was taken.
const OFFDUTY_SOURCE = { home: 'meter at home', checkin: 'typed at the office', manager: 'entered by a manager', log: 'manager log' };

// One off-duty night as a line: "03 Sep · EGL-682 · 17,734 → 17,755 · meter at home · 21 km"
const offEsc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function offDutyNightLine(n) {
  const fmt = v => (v == null ? '?' : Number(v).toLocaleString());
  const since = n.since ? ` <span style="color:#9CA3AF;">(since ${offEsc(n.since)})</span>` : '';
  const src = OFFDUTY_SOURCE[n.source] ? ` · ${OFFDUTY_SOURCE[n.source]}` : '';
  return `<div style="display:flex;gap:10px;align-items:baseline;padding:8px 4px;border-bottom:1px solid #F1F5F9;">
    <span style="font-size:13px;font-weight:700;color:#111827;min-width:92px;">${offEsc(n.date)}</span>
    <span style="flex:1;font-size:12.5px;color:#6B7280;">${n.vtype === 'van' ? '🚚' : '🏍'} ${offEsc(n.vehicle_label || '')} · ${fmt(n.from)} → ${fmt(n.to)}${src}${since}</span>
    <span style="font-size:13px;font-weight:700;color:#B45309;white-space:nowrap;">${Number(n.km).toLocaleString()} km</span>
  </div>`;
}

function showOffDutyNights(uid, name) {
  const u = monthData.find(x => String(x.user_id) === String(uid)) || {};
  const nights = u.offduty_nights || [];
  document.getElementById('bdTitle').textContent = `🌙 Off-duty km — ${name} · ${Number(u.offduty_km || 0).toLocaleString()} km`;
  document.getElementById('bdSub').textContent = 'company bike / van · his evening reading → his next morning reading · same list as Bikes → Riders';
  document.getElementById('bdBody').innerHTML = nights.length
    ? nights.map(offDutyNightLine).join('')
    : '<div style="padding:24px;text-align:center;color:#9CA3AF;font-size:13px;">No off-duty nights this month.</div>';
  document.getElementById('dateBreakdownModal').style.display = 'flex';
}

// Human labels for the violation codes coming from the server detail maps.
const VIOLATION_LABELS = {
  late_vs_eta: '⏱ arrived after the ride-in deadline',
  no_home_start: '🏠 no start meter from home',
  meter_gap: '🛑 morning meter gap vs last night',
  completed_late: '🌙 reached home late',
  late_locked: '🔒 never confirmed home — meter locked',
  unlocked: '🔓 manager had to unlock meter entry',
};

// The per-date violation popup (reuses the date-breakdown modal shell, client-side only).
function showViolationDetail(uid, name, kind) {
  const u = monthData.find(x => String(x.user_id) === String(uid)) || {};
  const detail = (kind === 'in' ? u.checkin_violation_detail : u.checkout_violation_detail) || {};
  const modal = document.getElementById('dateBreakdownModal');
  document.getElementById('bdTitle').textContent = `${kind === 'in' ? '🏍 Check-in violations' : '🌙 Check-out violations'} — ${name}`;
  document.getElementById('bdSub').textContent = kind === 'in'
    ? 'company bike · morning: home meter + ride-in deadline'
    : 'company bike · evening: ride home + closing meter';
  const entries = Object.entries(detail).sort((a, b) => a[0].localeCompare(b[0]));
  const bodyEl = document.getElementById('bdBody');
  // ⭐ Sep-30: on a morning the machine moved overnight, say by how much and on which plate —
  //   from the same off-duty list as the Off-duty km column (so "meter gap" has a number).
  const nightOn = {};
  if (kind === 'in') (u.offduty_nights || []).forEach(n => { (nightOn[n.date] = nightOn[n.date] || []).push(n); });
  const kmNote = (date) => (nightOn[date] || []).map(n =>
    ` · <b style="color:#B45309;">+${Number(n.km).toLocaleString()} km</b> ${offEsc(n.vehicle_label || '')}`
    + (n.from != null && n.to != null ? ` <span style="color:#9CA3AF;">(${Number(n.from).toLocaleString()} → ${Number(n.to).toLocaleString()})</span>` : '')).join('');
  bodyEl.innerHTML = entries.length
    ? entries.map(([date, issues]) =>
        `<div style="display:flex;gap:10px;align-items:baseline;padding:8px 4px;border-bottom:1px solid #F1F5F9;">
          <span style="font-size:13px;font-weight:700;color:#111827;min-width:92px;">${date}</span>
          <span style="font-size:12.5px;color:#6B7280;">${(issues || []).map(i => VIOLATION_LABELS[i] || i).join(' · ')}${kmNote(date)}</span>
        </div>`).join('')
    : '<div style="padding:24px;text-align:center;color:#9CA3AF;font-size:13px;">No detail available.</div>';
  modal.style.display = 'flex';
}
// The "Leave (bal)" cell: remaining leaves up top (the number a manager decides on), with taken
// context + where the extra/missing ones came from. Click → full balance + history summary.
function leaveBalCell(u, uid, nm) {
  if (u.leave_remaining == null || u.leave_remaining === undefined) {
    // Balance unavailable (old cache / error) — fall back to the plain taken-count drill.
    return numCell(u.leaves_taken_year, '#7C3AED', uid, nm, 'year_leave');
  }
  const rem = Number(u.leave_remaining);
  const taken = Number(u.leaves_taken_year) || 0;
  const quota = Number(u.leave_effective_quota) || 0;
  const remColor = rem <= 0 ? '#DC2626' : (rem <= 2 ? '#D97706' : '#15803D');
  const ot = Number(u.leave_earned_overtime) || 0;
  const late = Number(u.leave_late_penalties) || 0;
  const adj = Number(u.leave_manual_adjust) || 0;
  let chips = '';
  if (ot !== 0) chips += `<span style="color:#6d28d9;">${ot > 0 ? '+' : ''}${ot} OT</span> `;
  if (late < 0) chips += `<span style="color:#c2410c;">${late} late</span> `;
  if (adj !== 0) chips += `<span style="color:#6b7280;">${adj > 0 ? '+' : ''}${adj} adj</span> `;
  const cov = Number(u.leave_absence_cover) || 0;
  if (cov !== 0) chips += `<span style="color:#b45309;">${cov} absence cover</span> `;
  return `<td class="px-4 py-3" style="text-align:center;">
    <button type="button" onclick="event.stopPropagation(); showLeaveSummary(${uid}, '${nm}')" title="Leave balance + history"
      style="background:none;border:none;cursor:pointer;padding:0;">
      <div style="font-weight:800;font-size:14px;color:${remColor};line-height:1.1;">${rem}<span style="font-size:10px;font-weight:600;color:#9CA3AF;"> left</span></div>
      <div style="font-size:10px;color:#9CA3AF;margin-top:1px;">${taken} of ${quota} taken</div>
      ${chips ? `<div style="font-size:10px;margin-top:1px;">${chips.trim()}</div>` : ''}
    </button>
  </td>`;
}

// Full leave picture for one employee — balance formula + adjustment history (who/when/why) +
// the dates actually taken. Reuses the date-breakdown modal shell.
async function showLeaveSummary(uid, name) {
  const u = monthData.find(x => String(x.user_id) === String(uid)) || {};
  LVADJ = { uid: uid, name: name, kind: 'bonus' }; // default to bonus — the usual correction
  const modal = document.getElementById('dateBreakdownModal');
  document.getElementById('bdTitle').textContent = `🏖 Leave — ${name}`;
  document.getElementById('bdSub').textContent = 'balance · adjustments · dates taken (year cycle)';
  const bodyEl = document.getElementById('bdBody');
  bodyEl.innerHTML = '<div style="padding:24px;text-align:center;color:#9CA3AF;font-size:13px;">Loading…</div>';
  modal.style.display = 'flex';

  const rem = Number(u.leave_remaining) || 0;
  const remColor = rem <= 0 ? '#DC2626' : (rem <= 2 ? '#D97706' : '#15803D');
  const quotaBase = Number(u.leave_quota_total) || 0;
  const ot = Number(u.leave_earned_overtime) || 0;
  const late = Number(u.leave_late_penalties) || 0;
  const adj = Number(u.leave_manual_adjust) || 0;
  const taken = Number(u.leaves_taken_year) || 0;

  const fRow = (label, val, color) => `<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;"><span style="color:#6B7280;">${label}</span><span style="font-weight:600;color:${color || '#111827'};">${val}</span></div>`;
  let summary = `<div style="background:#F9FAFB;border:1px solid #F3F4F6;border-radius:8px;padding:10px 12px;margin-bottom:12px;">`;
  summary += `<div style="text-align:center;margin-bottom:6px;"><span style="font-size:26px;font-weight:800;color:${remColor};">${rem}</span><span style="font-size:13px;color:#6B7280;font-weight:600;"> leaves left</span></div>`;
  summary += fRow('Yearly quota', quotaBase);
  // Bonus can go NEGATIVE now (a correction can outweigh what was earned) — always signed.
  if (ot !== 0) summary += fRow((ot > 0 ? '+ ' : '− ') + 'Bonus (overtime)', (ot > 0 ? '+' : '') + ot, '#6d28d9');
  if (late < 0) summary += fRow('− Late penalty', late, '#c2410c');
  if (adj !== 0) summary += fRow((adj > 0 ? '+ ' : '− ') + 'Yearly adjustment', (adj > 0 ? '+' : '') + adj, '#6b7280');
  const cov = Number(u.leave_absence_cover) || 0;
  if (cov !== 0) summary += fRow('− Used to cover parked absences', cov, '#b45309');
  summary += fRow('− Taken', '−' + taken, '#2563eb');
  summary += `<div style="border-top:1px dashed #E5E7EB;margin-top:4px;padding-top:4px;">` + fRow('= Remaining', rem, remColor) + `</div>`;
  summary += `</div>`;

  const dow = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'], mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const fmtD = (ds) => { if (!ds) return ''; const d = new Date(ds + 'T00:00:00'); return `${dow[d.getDay()]}, ${d.getDate()} ${mon[d.getMonth()]} '${String(d.getFullYear()).slice(2)}`; };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
  try {
    const [adjRes, takenRes] = await Promise.all([
      fetch('/attendance/date-breakdown?type=leave_grants&user_id=' + uid).then(r => r.json()).catch(() => ({})),
      fetch('/attendance/date-breakdown?type=year_leave&user_id=' + uid + '&month=' + currentMonthValue()).then(r => r.json()).catch(() => ({})),
    ]);
    const adjs = (adjRes && adjRes.success) ? (adjRes.dates || []) : [];
    let adjHtml = `<div style="font-size:12px;font-weight:700;color:#374151;margin:4px 0 6px;">Adjustments — overtime / late / manual</div>`;
    adjHtml += adjs.length
      ? adjs.map(a => `<div style="display:flex;justify-content:space-between;gap:10px;padding:6px 4px;border-bottom:1px solid #F3F4F6;"><span style="font-size:12.5px;color:#111827;">${esc(a.label || '')}</span><span style="font-size:11px;color:#9CA3AF;white-space:nowrap;">${fmtD(a.date)}</span></div>`).join('')
      : `<div style="font-size:12px;color:#9CA3AF;padding:4px;">None this cycle.</div>`;
    const takens = (takenRes && takenRes.success) ? (takenRes.dates || []) : [];
    let takenHtml = `<div style="font-size:12px;font-weight:700;color:#374151;margin:12px 0 6px;">Leave dates taken (${takens.length})</div>`;
    takenHtml += takens.length
      ? takens.map(t => `<div style="display:flex;justify-content:space-between;gap:10px;padding:6px 4px;border-bottom:1px solid #F3F4F6;"><span style="font-size:12.5px;color:#111827;">${fmtD(t.date)}</span>${t.label ? `<span style="font-size:11px;color:#7C3AED;background:#7C3AED14;border-radius:5px;padding:1px 7px;font-weight:600;">${esc(t.label)}</span>` : ''}</div>`).join('')
      : `<div style="font-size:12px;color:#9CA3AF;padding:4px;">None this cycle.</div>`;
    // ± Adjust bar — the manager picks WHICH pool they are changing (bonus vs yearly)
    // before typing days, so a correction can never land in the wrong bucket.
    const adjustBar = `<div style="border:1px solid #E5E7EB;border-radius:8px;padding:10px 12px;margin-bottom:12px;background:#FFFFFF;">
      <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;">± Adjust leaves</div>
      <div style="display:flex;gap:6px;margin-bottom:6px;">
        <button type="button" id="lvKindBonus" onclick="lvAdjSetKind('bonus')">🏅 Bonus (overtime)</button>
        <button type="button" id="lvKindYearly" onclick="lvAdjSetKind('yearly')">📅 Yearly allowance</button>
      </div>
      <div id="lvAdjHint" style="font-size:11px;color:#6B7280;margin-bottom:8px;"></div>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
        <button type="button" onclick="lvAdjStep(-1)" style="width:30px;height:32px;border:1px solid #D1D5DB;border-radius:6px;background:#fff;font-size:15px;font-weight:700;color:#374151;cursor:pointer;">−</button>
        <input type="number" id="lvAdjDays" value="-1" step="0.5" style="width:64px;height:32px;text-align:center;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;font-weight:700;">
        <button type="button" onclick="lvAdjStep(1)" style="width:30px;height:32px;border:1px solid #D1D5DB;border-radius:6px;background:#fff;font-size:15px;font-weight:700;color:#374151;cursor:pointer;">＋</button>
        <input type="text" id="lvAdjReason" maxlength="200" placeholder="Reason — e.g. added by mistake" style="flex:1;min-width:140px;height:32px;border:1px solid #D1D5DB;border-radius:6px;padding:0 8px;font-size:12.5px;">
        <button type="button" id="lvAdjSave" onclick="saveLeaveAdjust()" style="height:32px;padding:0 14px;border:none;border-radius:6px;background:#16a34a;color:#fff;font-size:12.5px;font-weight:700;cursor:pointer;">Save</button>
      </div>
    </div>`;
    bodyEl.innerHTML = summary + adjustBar + adjHtml + takenHtml + '<div id="undoLeaveWrap"></div>';
    lvAdjSetKind(LVADJ.kind); // paint the toggle + hint for the default pool
    // Approved leaves this cycle — each can be UNDONE if approved by mistake (returns the day to the balance).
    loadApprovedLeavesForUndo(uid, name);
  } catch (e) {
    bodyEl.innerHTML = summary + '<div style="color:#DC2626;font-size:12px;padding:8px;">Could not load history.</div>';
  }
}

// ── ± Adjust leaves (inside the Leave summary modal) ─────────────────────────
// Two explicit pools, chosen BEFORE typing days so the manager always knows which
// one they are changing. 'bonus' writes a signed source='overtime' row (nets against
// payroll's overtime grants — a −1 cancels a bonus day given by mistake); 'yearly'
// writes source='manual' (one-off change on top of the yearly quota, the original
// give-extra-days behaviour). Days are SIGNED: negative removes.
let LVADJ = { uid: 0, name: '', kind: 'bonus' };

function lvAdjSetKind(kind) {
  LVADJ.kind = kind;
  const base = 'font-size:12px;padding:5px 10px;border-radius:6px;cursor:pointer;';
  const b = document.getElementById('lvKindBonus'), y = document.getElementById('lvKindYearly');
  if (b) b.style.cssText = base + (kind === 'bonus'
    ? 'font-weight:700;border:1px solid #6d28d9;background:#6d28d914;color:#6d28d9;'
    : 'font-weight:600;border:1px solid #E5E7EB;background:#fff;color:#6B7280;');
  if (y) y.style.cssText = base + (kind === 'yearly'
    ? 'font-weight:700;border:1px solid #2563eb;background:#2563eb14;color:#2563eb;'
    : 'font-weight:600;border:1px solid #E5E7EB;background:#fff;color:#6B7280;');
  const hint = document.getElementById('lvAdjHint');
  if (hint) hint.textContent = kind === 'bonus'
    ? 'Changes the overtime-earned bonus pool — e.g. −1 removes a bonus day granted by mistake. The yearly quota is untouched.'
    : 'One-off extra (or removed) days on top of the yearly quota — shows as "Yearly adjustment".';
}

function lvAdjStep(d) {
  const inp = document.getElementById('lvAdjDays');
  if (!inp) return;
  let v = (parseFloat(inp.value) || 0) + d;
  if (v === 0) v = d > 0 ? 1 : -1; // skip 0 — a zero-day adjustment is meaningless
  inp.value = v;
}

async function saveLeaveAdjust() {
  const days = parseFloat((document.getElementById('lvAdjDays') || {}).value);
  if (!days || isNaN(days)) { alert('Enter the number of days (negative to remove).'); return; }
  const reason = ((document.getElementById('lvAdjReason') || {}).value || '').trim();
  const what = LVADJ.kind === 'bonus' ? 'BONUS (overtime)' : 'YEARLY-allowance';
  if (!confirm((days > 0 ? 'Add ' : 'Remove ') + Math.abs(days) + ' ' + what + ' leave day(s) ' +
      (days > 0 ? 'to ' : 'from ') + LVADJ.name + '?')) return;
  const btn = document.getElementById('lvAdjSave');
  if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
  try {
    const post = (cover) => fetch('/attendance/grant-leave', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify(Object.assign(
        { user_id: LVADJ.uid, days: days, reason: reason, kind: LVADJ.kind },
        cover === undefined ? {} : { cover_absences: cover ? 1 : 0 }
      ))
    });
    let res = await post();
    let j = await res.json();
    // 🗓 A bonus day is what settles a parked absence, so the server asks first rather than
    // silently spending it. Answering re-submits the same grant with the choice attached.
    if (!j.success && j.needs_absence_choice) {
      const useIt = confirm(j.message + '\n\nOK = settle the absence · Cancel = give them all as leave');
      res = await post(useIt);
      j = await res.json();
    }
    if (!j.success) { alert(j.message || 'Could not save the adjustment.'); return; }
    if (j.message) { alert(j.message); }
    // Patch this row from the returned balance so the re-rendered modal is instantly
    // correct, then re-sync the whole grid in the background.
    const u = monthData.find(x => String(x.user_id) === String(LVADJ.uid));
    if (u && j.balance) {
      u.leave_remaining = j.balance.remaining;
      u.leave_quota_total = j.balance.quota_total;
      u.leave_earned_overtime = j.balance.earned_overtime;
      u.leave_late_penalties = j.balance.late_penalties;
      u.leave_manual_adjust = j.balance.manual_adjust;
      u.leave_absence_cover = j.balance.absence_cover || 0;
      u.leaves_taken_year = j.balance.taken_total;
    }
    showLeaveSummary(LVADJ.uid, LVADJ.name);
    loadMonthTab();
  } catch (e) {
    alert('Could not save the adjustment.');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
  }
}

// R3 — list approved leaves with an Undo button (manager fixes a mistaken approval).
async function loadApprovedLeavesForUndo(uid, name) {
  const wrap = document.getElementById('undoLeaveWrap');
  if (!wrap) return;
  try {
    const res = await fetch('/attendance/approved-leaves?user_id=' + uid).then(r => r.json());
    const leaves = (res && res.success) ? (res.leaves || []) : [];
    if (!leaves.length) { wrap.innerHTML = ''; return; }
    const fmt = (s) => { try { return new Date(s + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }); } catch(e){ return s; } }
    let h = `<div style="font-size:12px;font-weight:700;color:#374151;margin:14px 0 6px;">Approved leaves — undo a mistaken approval</div>`;
    h += leaves.map(l => {
      const range = l.start === l.end ? fmt(l.start) : (fmt(l.start) + ' – ' + fmt(l.end));
      return `<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:7px 4px;border-bottom:1px solid #F3F4F6;">
        <span style="font-size:12.5px;color:#111827;">${range} · <span style="color:#6B7280;">${String(l.type||'').toLowerCase()==='half_day' ? leaveTypeLabel(l.type) : l.days + 'd ' + escLeave(l.type)}</span></span>
        <button type="button" onclick="undoApprovedLeave(${l.id}, '${escLeave(name).replace(/'/g,"\\'")}')" style="font-size:11px;font-weight:700;color:#B45309;background:#FEF3C7;border:1px solid #FDE68A;border-radius:6px;padding:4px 10px;cursor:pointer;">↩ Undo</button>
      </div>`;
    }).join('');
    wrap.innerHTML = h;
  } catch (e) { wrap.innerHTML = ''; }
}

async function undoApprovedLeave(id, name) {
  if (!confirm(`Undo this approved leave for ${name}?\n\nThe day(s) return to their balance. Those dates become ABSENT unless you apply the correct leave.`)) return;
  const reason = prompt('Reason (optional) — e.g. "approved on the wrong day":', '') || '';
  try {
    const res = await fetch('/attendance/leave-request/' + id + '/undo', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ reason })
    });
    const j = await res.json();
    if (j.success) {
      alert(j.message || 'Leave approval undone.');
      // Refresh the summary + the day table so the returned day shows correctly.
      if (typeof loadAttendanceForDate === 'function') loadAttendanceForDate();
      if (typeof loadMonthTab === 'function') loadMonthTab();
      closeDateBreakdown();
    } else {
      alert(j.message || 'Could not undo the leave.');
    }
  } catch (e) { alert('Could not undo the leave.'); }
}

function openMonthDetail(userId, name, filter) {
  if (typeof showEmployeeDetails !== 'function') return;
  // Scope the detail to the SELECTED month (1st → last day), not a rolling 30-day window.
  // W3: an optional filter pre-selects a stat tile (Late/Absent/Present count clicks).
  const m = currentMonthValue();                       // 'YYYY-MM'
  const y = parseInt(m.slice(0, 4), 10), mo = parseInt(m.slice(5, 7), 10);
  const start = m + '-01';
  const end = m + '-' + String(new Date(y, mo, 0).getDate()).padStart(2, '0'); // last day of month
  const label = new Date(m + '-01T00:00:00').toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
  showEmployeeDetails(userId, name, end, start, end, label, filter || null);
}

// W1 — from the TODAY tab: month-of-the-selected-date summary + day detail (the same
// popup the Month tab opens, so the numbers always agree). Newest day first.
function openTodayDetail(userId, name) {
  if (typeof showEmployeeDetails !== 'function') return;
  const sel = (document.getElementById('tableDate') || {}).value || new Date().toISOString().split('T')[0];
  const m = sel.slice(0, 7);                            // month of the selected Today date
  const y = parseInt(m.slice(0, 4), 10), mo = parseInt(m.slice(5, 7), 10);
  const start = m + '-01';
  const end = m + '-' + String(new Date(y, mo, 0).getDate()).padStart(2, '0');
  const label = new Date(m + '-01T00:00:00').toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
  showEmployeeDetails(userId, name, sel, start, end, label);
}

// Pretty leave-type label shared by every undo list ('half_day' → ½ day etc.).
function leaveTypeLabel(t, days) {
  const s = String(t || 'leave').toLowerCase();
  if (s === 'half_day') return '🌗 ½ day (0.5)';
  return `${days != null ? days + 'd ' : ''}${s}`;
}

// W2 — the On-Leave / half-day chip on a TODAY row opens this: the person's approved
// leaves with an Undo on each (same endpoint + undo as the Month tab's balance sheet),
// so a wrong approval can be fixed without leaving the Today view.
async function openLeaveActions(userId, name) {
  const modal = document.getElementById('dateBreakdownModal');
  if (!modal) return;
  document.getElementById('bdTitle').textContent = `🏖 Leaves — ${name}`;
  document.getElementById('bdSub').textContent = 'this year cycle · undo a mistaken approval';
  const bodyEl = document.getElementById('bdBody');
  bodyEl.innerHTML = '<div style="padding:24px;text-align:center;color:#9CA3AF;font-size:13px;">Loading…</div>';
  modal.style.display = 'flex';
  try {
    const res = await fetch('/attendance/approved-leaves?user_id=' + userId).then(r => r.json());
    const leaves = (res && res.success) ? (res.leaves || []) : [];
    if (!leaves.length) {
      bodyEl.innerHTML = '<div style="padding:24px;text-align:center;color:#9CA3AF;font-size:13px;">No approved leaves this cycle.</div>';
      return;
    }
    const fmt = (s) => { try { return new Date(s + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }); } catch(e){ return s; } };
    bodyEl.innerHTML = leaves.map(l => {
      const range = l.start === l.end ? fmt(l.start) : (fmt(l.start) + ' – ' + fmt(l.end));
      const isHalf = String(l.type || '').toLowerCase() === 'half_day';
      return `<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 4px;border-bottom:1px solid #F3F4F6;">
        <span style="font-size:13px;color:#111827;">${range} · <span style="color:#6B7280;">${isHalf ? leaveTypeLabel(l.type) : escLeave(String(l.days)) + 'd ' + escLeave(l.type)}</span></span>
        <button type="button" onclick="undoApprovedLeave(${l.id}, '${escLeave(name).replace(/'/g, "\\'")}')" style="font-size:11px;font-weight:700;color:#B45309;background:#FEF3C7;border:1px solid #FDE68A;border-radius:6px;padding:4px 10px;cursor:pointer;">↩ Undo</button>
      </div>`;
    }).join('');
  } catch (e) {
    bodyEl.innerHTML = '<div style="padding:24px;text-align:center;color:#DC2626;font-size:13px;">Could not load the leaves.</div>';
  }
}

// ---- Month-tab date drill-down (exact dates behind a clicked count) ----
const BREAKDOWN_META = {
  month_absent: { title: 'Absent days',       sub: 'this month',      color: '#DC2626', icon: '❌' },
  month_leave:  { title: 'Leave days',        sub: 'this month',      color: '#2563EB', icon: '🏖' },
  year_leave:   { title: 'Leave days',        sub: 'this year cycle', color: '#7C3AED', icon: '🏖' },
  year_absent:  { title: 'Absent days',       sub: 'this year cycle', color: '#B91C1C', icon: '❌' },
  month_overtime: { title: 'Overtime days',   sub: 'this month',      color: '#047857', icon: '⏱' },
  month_late:     { title: 'Late days',       sub: 'this month',      color: '#B45309', icon: '⏰' },
  month_office_checkout: { title: 'Office checkouts', sub: 'delivered but came back to the office', color: '#92400E', icon: '🏢' },
  month_meter_missed: { title: 'Missed meter readings', sub: 'finished working days only — never absences, leave or future days', color: '#C2410C', icon: '⛽' },
};
// The evidence line under a drill-down date — currently only Overtime days sends it
// (item.meta). Shows the day as WORKED: check-in, the checkout AS RECORDED, and, when that
// checkout rode a manager bypass, the end the overtime was actually counted to (the last
// delivered order). Without it a manager sees "3h 13m" beside a 00:05 checkout and cannot
// tell whether the rider really worked past midnight. Returns '' when no meta — every other
// breakdown type renders exactly as before.
function bdDayMeta(m) {
  if (!m) return '';
  const num = (v) => String(v).replace(/</g, '&lt;');
  const strong = (v) => `<b style="color:#111827;font-weight:600;">${num(v)}</b>`;
  // Late day (month_late): the shift start the check-in was judged against, then the check-in.
  if (m.shift_start || (m.login && !m.logout)) {
    const lb = [];
    if (m.shift_start) lb.push('shift ' + strong(m.shift_start));
    if (m.login) lb.push('in ' + strong(m.login));
    return lb.length
      ? `<div style="font-size:11px;color:#6B7280;margin-top:2px;" title="Checked in at ${num(m.login || '?')} against a ${num(m.shift_start || '?')} shift start.">`
        + lb.join(' <span style="color:#D1D5DB;">→</span> ') + '</div>'
      : '';
  }
  const bits = [];
  if (m.login)  bits.push('in ' + strong(m.login));
  if (m.logout) bits.push('out ' + strong(m.logout));
  const worked = fmtMinsShort(m.worked_minutes), target = fmtMinsShort(m.target_minutes);
  const sums = `Counted ${worked} worked against a ${target} target.`;
  let html = bits.length
    ? `<div style="font-size:11px;color:#6B7280;margin-top:2px;" title="${sums}">` +
      bits.join(' <span style="color:#D1D5DB;">→</span> ') + '</div>'
    : '';
  const cc = m.counted;
  if (cc && cc.time) {
    html += `<div style="font-size:11px;font-weight:700;color:#B45309;margin-top:2px;" ` +
      `title="This checkout used a manager bypass, so its time is when the valve was used — not when the work ended. The day is counted up to the last delivered order at ${num(cc.time)}. ${sums}">` +
      `🔓 bypassed · counted ${num(cc.time)} · last delivery</div>`;
  }
  // How busy the day actually was — the case for the overtime, beside the hours themselves.
  if (m.orders) {
    const span = (m.first_delivery && m.last_delivery)
      ? ` · ${num(m.first_delivery)} → ${num(m.last_delivery)}` : '';
    html += `<div style="font-size:11px;color:#6B7280;margin-top:2px;" title="Orders this rider delivered on this day, first to last.">` +
      `📦 ${num(m.orders)} order${m.orders === 1 ? '' : 's'} delivered${span}</div>`;
  }
  return html + bdReviewLine(m);
}

/**
 * ⭐ Sep-6 2026 — the day-review verdict, in the SAME words the Payroll drill uses.
 * Read-only here on purpose: this page is where a manager LOOKS at a month, the bulb and the
 * payroll drill are where he acts. Showing the verdict but not the buttons keeps one place
 * to decide while still telling the truth on both screens.
 */
function bdReviewLine(m) {
  const r = m && m.review;
  if (!r) { return ''; }
  const esc2 = (v) => String(v === null || v === undefined ? '' : v).replace(/</g, '&lt;');
  const who = [r.by ? `by ${esc2(r.by)}` : '', r.at || ''].filter(Boolean).join(' · ');
  let txt;
  if (r.verdict === 'verified') { txt = '✔ verified'; }
  else if (m.waived) { txt = `⏳ ${fmtMinsShort(m.waived)} waived`; }
  else if (r.verdict === 'waived') { txt = '✕ not overtime'; }
  else { txt = '✎ adjusted'; }
  return `<div style="font-size:10.5px;font-weight:700;color:#047857;margin-top:2px;">` +
    `${txt}${who ? ` · ${esc2(who)}` : ''}${r.reason ? ` · “${esc2(r.reason)}”` : ''}</div>`;
}

async function showDateBreakdown(userId, name, type) {
  const meta = BREAKDOWN_META[type] || { title: 'Dates', sub: '', color: '#374151', icon: '📅' };
  const modal = document.getElementById('dateBreakdownModal');
  document.getElementById('bdTitle').textContent = `${meta.icon} ${meta.title} — ${name}`;
  document.getElementById('bdSub').textContent = meta.sub;
  const bodyEl = document.getElementById('bdBody');
  bodyEl.innerHTML = '<div style="padding:24px;text-align:center;color:#9CA3AF;font-size:13px;">Loading…</div>';
  modal.style.display = 'flex';
  try {
    const res = await fetch(`/attendance/date-breakdown?user_id=${userId}&type=${type}&month=${currentMonthValue()}`);
    const json = await res.json();
    const dates = (json && json.success) ? (json.dates || []) : [];
    if (!dates.length) {
      bodyEl.innerHTML = '<div style="padding:24px;text-align:center;color:#9CA3AF;font-size:13px;">No dates found.</div>';
      return;
    }
    const dow = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const rows = dates.map(item => {
      const d = new Date(item.date + 'T00:00:00');
      const label = `${dow[d.getDay()]}, ${d.getDate()} ${mon[d.getMonth()]} ${d.getFullYear()}`;
      const tag = item.label ? `<span style="font-size:11px;color:${meta.color};background:${meta.color}14;border-radius:5px;padding:1px 7px;font-weight:600;white-space:nowrap;">${String(item.label).replace(/</g,'&lt;')}</span>` : '';
      const sub = bdDayMeta(item.meta);
      return `<div style="display:flex;align-items:${sub ? 'flex-start' : 'center'};justify-content:space-between;gap:10px;padding:8px 4px;border-bottom:1px solid #F3F4F6;">
        <div style="min-width:0;"><div style="font-size:13px;color:#111827;">${label}</div>${sub}</div>${tag}
      </div>`;
    }).join('');
    bodyEl.innerHTML = `<div style="font-size:12px;color:#6B7280;margin-bottom:8px;">${dates.length} day${dates.length>1?'s':''}</div>${rows}`;
  } catch (e) {
    bodyEl.innerHTML = '<div style="padding:24px;text-align:center;color:#DC2626;font-size:13px;">Could not load the dates.</div>';
  }
}
function closeDateBreakdown() { document.getElementById('dateBreakdownModal').style.display = 'none'; }
function exportMonthCsv() {
  if (!monthData.length) { alert('Nothing to export.'); return; }
  // Flags are split back out here — a spreadsheet wants one number per column, unlike the table
  // where they read better as one group of chips.
  // ⚠ "Late minutes" is NET of anything a manager waived, so the waiver and the raw figure
  // travel beside it — otherwise the sheet holds a number its own day rows do not add up to.
  let csv = 'Employee,Present,Absent (month),Leave (month),Late minutes,Late waived,Late before waiver,Morning flags,Evening flags,Office checkouts,Missed meter,Off-duty km,Leave (year),Absent (year)\n';
  [...monthData].sort((a, b) => String(a.fullname || '').localeCompare(String(b.fullname || ''))).forEach(u => {
    csv += `"${(u.fullname || '').replace(/"/g, '""')}",${u.present_days || 0},${u.absent_days || 0},${u.leave_days || 0},${u.total_late_minutes || 0},${u.late_waived_minutes || 0},${u.late_raw_minutes || u.total_late_minutes || 0},${u.checkin_violation_days || 0},${u.checkout_violation_days || 0},${u.office_checkout_days || 0},${u.meter_missed_days || 0},${u.offduty_km == null ? "" : u.offduty_km},${u.leaves_taken_year || 0},${u.absent_days_year || 0}\n`;
  });
  const blob = new Blob([csv], { type: 'text/csv' });
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
  a.download = `attendance-${currentMonthValue()}.csv`; a.click();
}

// ---- Calendar tab ----
let calYear, calMonth, calHolidays = {};
async function loadCalendarTab() {
  if (calYear === undefined) { const d = new Date(); calYear = d.getFullYear(); calMonth = d.getMonth(); }
  try {
    const res = await fetch('/holidays/list?year=' + calYear);
    const json = await res.json();
    calHolidays = {};
    (json.data || []).forEach(h => { calHolidays[h.holiday_date] = h.holiday_name; });
  } catch (e) { calHolidays = {}; }
  renderCalendar();
}
let calRangeStart = null; // 'Y-m-d' while picking a range end, else null
function calStep(delta) {
  calMonth += delta;
  if (calMonth < 0) { calMonth = 11; calYear--; }
  else if (calMonth > 11) { calMonth = 0; calYear++; }
  loadCalendarTab();
}
function calCancelSelect() { calRangeStart = null; renderCalendar(); }
function updateCalHint() {
  const hint = document.getElementById('calHint');
  const cancel = document.getElementById('calCancelBtn');
  if (calRangeStart) {
    if (hint) hint.textContent = `Start: ${calRangeStart}. Now click the last day of the holiday (or the same day for one day).`;
    if (cancel) cancel.style.display = 'inline-block';
  } else {
    if (hint) hint.textContent = 'Click a day to add a holiday — or click a start day then an end day for a range.';
    if (cancel) cancel.style.display = 'none';
  }
}
function renderCalendar() {
  const label = document.getElementById('calLabel');
  const grid = document.getElementById('calGrid');
  const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  label.textContent = monthNames[calMonth] + ' ' + calYear;
  const first = new Date(calYear, calMonth, 1);
  const startDow = (first.getDay() + 6) % 7; // Mon = 0
  const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
  const pad = n => String(n).padStart(2, '0');
  let html = '';
  for (let i = 0; i < startDow; i++) html += '<div></div>';
  for (let day = 1; day <= daysInMonth; day++) {
    const ds = `${calYear}-${pad(calMonth + 1)}-${pad(day)}`;
    const isHol = calHolidays[ds];
    const isStart = (ds === calRangeStart);
    let box = 'background:#ffffff;border:1px solid #e5e7eb;';
    if (isHol) box = 'background:#FEE2E2;border:1px solid #FCA5A5;';
    if (isStart) box = 'background:#DBEAFE;border:2px solid #2563EB;';
    const nameHtml = isHol ? `<div style="font-size:9px;color:#b91c1c;line-height:1.1;margin-top:2px;overflow:hidden;">${String(isHol).replace(/</g,'&lt;')}</div>` : '';
    const t = isHol ? 'Click to remove this holiday' : (calRangeStart ? 'Click to set as the end day' : 'Click to add a holiday');
    html += `<div title="${t}" onclick="calDayClick('${ds}')" onmouseover="this.style.filter='brightness(0.97)'" onmouseout="this.style.filter=''" style="min-height:48px;border-radius:8px;padding:5px 6px;cursor:pointer;${box}">
      <div style="font-size:12px;font-weight:600;color:${isHol ? '#b91c1c' : (isStart ? '#1D4ED8' : '#374151')}">${day}</div>${nameHtml}
    </div>`;
  }
  grid.innerHTML = html;
  updateCalHint();
}
async function calDayClick(ds) {
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  // Clicking an existing holiday always means "remove it".
  if (calHolidays[ds]) {
    calRangeStart = null;
    if (!confirm(`Remove holiday "${calHolidays[ds]}" on ${ds}?`)) { renderCalendar(); return; }
    try {
      const res = await fetch('/holidays/list?year=' + calYear);
      const json = await res.json();
      const h = (json.data || []).find(x => x.holiday_date === ds);
      if (!h) { loadCalendarTab(); return; }
      const del = await fetch('/holidays/' + h.id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf } });
      const dj = await del.json();
      if (dj.success) loadCalendarTab(); else alert(dj.message || 'Could not remove the holiday.');
    } catch (e) { alert('Could not remove the holiday.'); }
    return;
  }
  // First click sets the range start; second click sets the end. No date typing.
  if (!calRangeStart) {
    calRangeStart = ds;
    renderCalendar();
    return;
  }
  const from = calRangeStart <= ds ? calRangeStart : ds;
  const to = calRangeStart <= ds ? ds : calRangeStart;
  calRangeStart = null;
  const label = (from === to) ? from : `${from} → ${to}`;
  const name = prompt(`Holiday name for ${label}:`);
  renderCalendar();
  if (!name) return;
  try {
    const res = await fetch('/holidays', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ holiday_date: from, holiday_end_date: (from === to ? null : to), holiday_name: name })
    });
    const json = await res.json();
    if (json.success) loadCalendarTab(); else alert(json.message || 'Could not add the holiday.');
  } catch (e) { alert('Could not add the holiday.'); }
}

// ── Apply-leave modal (manager on-behalf, auto-approved) ─────────────────────
function openApplyLeave() {
  const sel = document.getElementById('leaveUser');
  const seen = {};
  const opts = (allAttendanceData || [])
    .filter(u => u.user_id && !seen[u.user_id] && (seen[u.user_id] = true))
    .sort((a, b) => String(a.fullname || '').localeCompare(String(b.fullname || '')))
    .map(u => `<option value="${u.user_id}">${(u.fullname || ('#' + u.user_id))}</option>`).join('');
  sel.innerHTML = opts || '<option value="">No users loaded — load a date first</option>';
  const today = (document.getElementById('tableDate') || {}).value || new Date().toISOString().split('T')[0];
  document.getElementById('leaveFrom').value = today;
  document.getElementById('leaveTo').value = today;
  document.getElementById('leaveTo').min = today;
  document.getElementById('leaveNote').value = '';
  document.getElementById('leaveType').value = 'planned'; // default every open
  document.getElementById('applyLeaveModal').style.display = 'flex';
  loadLeaveBalanceChip();
}
function closeApplyLeave() { document.getElementById('applyLeaveModal').style.display = 'none'; }

// Show the chosen rider's current leave balance in the modal + a "give extra" link.
async function loadLeaveBalanceChip() {
  const chip = document.getElementById('leaveBalanceChip');
  const uid = document.getElementById('leaveUser').value;
  if (!chip) return;
  // Reset the history drawer whenever the selected rider changes.
  const hbox = document.getElementById('leaveHistoryBox');
  if (hbox) { hbox.style.display = 'none'; hbox.innerHTML = ''; hbox.dataset.uid = ''; }
  if (!uid) { chip.innerHTML = ''; return; }
  chip.innerHTML = '<span style="color:#9ca3af;">Loading balance…</span>';
  try {
    const res = await fetch('/attendance/leave-balance?user_id=' + uid);
    const j = await res.json();
    if (!j.success) { chip.innerHTML = ''; return; }
    const b = j.balance;
    const remColor = b.remaining <= 0 ? '#dc2626' : (b.remaining <= 2 ? '#d97706' : '#15803d');
    // Where the extra/missing leaves came from — segregated so a grown quota isn't a mystery.
    let breakdown = '';
    if (b.earned_overtime != 0) breakdown += `<span style="color:#6d28d9;">${b.earned_overtime > 0 ? '+' : ''}${b.earned_overtime} bonus</span>`;
    if (b.late_penalties < 0) breakdown += `<span style="color:#c2410c;">${b.late_penalties} late</span>`;
    if (b.manual_adjust && b.manual_adjust != 0) breakdown += `<span style="color:#6b7280;">${b.manual_adjust > 0 ? '+' : ''}${b.manual_adjust} adj</span>`;
    if (b.absence_cover && b.absence_cover != 0) breakdown += `<span style="color:#b45309;">${b.absence_cover} absence cover</span>`;
    chip.innerHTML =
      `<span style="font-weight:600;color:${remColor};">${b.remaining} of ${b.effective_quota} leaves left</span>` +
      `<span style="color:#9ca3af;">· same-day used ${b.sameday_used}/${b.sameday_cap}</span>` +
      breakdown +
      `<button type="button" onclick="toggleLeaveHistory(${uid})" style="background:none;border:none;color:#2563eb;cursor:pointer;text-decoration:underline;font-size:12px;padding:0;">history ›</button>` +
      `<button type="button" onclick="grantExtraLeave(${uid})" style="background:none;border:none;color:#2563eb;cursor:pointer;text-decoration:underline;font-size:12px;padding:0;">＋ adjust yearly days</button>`;
  } catch (e) { chip.innerHTML = ''; }
}

// Dated, attributed leave adjustments (overtime bonus / late penalty / manual) — who/when/why.
async function toggleLeaveHistory(uid) {
  const box = document.getElementById('leaveHistoryBox');
  if (!box) return;
  if (box.style.display !== 'none' && box.dataset.uid === String(uid)) { box.style.display = 'none'; return; }
  const escLh = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  box.dataset.uid = String(uid);
  box.style.display = 'block';
  box.innerHTML = '<span style="color:#9ca3af;">Loading…</span>';
  try {
    const res = await fetch('/attendance/date-breakdown?type=leave_grants&user_id=' + uid);
    const j = await res.json();
    if (!j.success || !j.dates || !j.dates.length) {
      box.innerHTML = '<span style="color:#9ca3af;">No overtime / late / manual leave adjustments this cycle.</span>';
      return;
    }
    box.innerHTML = j.dates.map(d => {
      const dt = d.date ? new Date(d.date + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
      return `<div style="display:flex;justify-content:space-between;gap:8px;padding:3px 0;border-bottom:1px solid #f3f4f6;"><span>${escLh(d.label || '')}</span><span style="color:#9ca3af;white-space:nowrap;">${dt}</span></div>`;
    }).join('');
  } catch (e) {
    box.innerHTML = '<span style="color:#dc2626;">Could not load history.</span>';
  }
}

// YEARLY-allowance adjustments only (source='manual'). Bonus (overtime) days are
// adjusted from the Month tab → click the LEAVE (BAL) number → ± Adjust leaves.
async function grantExtraLeave(uid) {
  const raw = prompt('How many extra YEARLY-allowance days to give? (use a negative number to deduct)\n\nTip: bonus/overtime days are adjusted from the Month tab leave popup.');
  if (raw === null) return;
  const days = parseFloat(raw);
  if (!days || isNaN(days)) { alert('Enter a number of days.'); return; }
  const reason = prompt('Reason (optional):') || '';
  try {
    const res = await fetch('/attendance/grant-leave', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ user_id: uid, days: days, reason: reason, kind: 'yearly' })
    });
    const j = await res.json();
    alert(j.message || (j.success ? 'Done.' : 'Could not save.'));
    if (j.success) loadLeaveBalanceChip();
  } catch (e) { alert('Could not save the grant.'); }
}
function syncLeaveToMin() {
  const f = document.getElementById('leaveFrom').value;
  const to = document.getElementById('leaveTo');
  to.min = f || '';
  if (to.value && f && to.value < f) to.value = f;
  // Half day = single date: keep To glued to From.
  if ((document.getElementById('leaveType') || {}).value === 'half_day' && f) to.value = f;
}

// Half day is a SINGLE date (0.5 leave, no late/OT counted that day) — lock the To
// field to From and explain; other types restore the normal range behaviour.
function onLeaveTypeChange() {
  const type = (document.getElementById('leaveType') || {}).value;
  const to = document.getElementById('leaveTo');
  const hint = document.getElementById('leaveTypeHint');
  if (type === 'half_day') {
    const f = document.getElementById('leaveFrom').value;
    if (f) to.value = f;
    to.disabled = true;
    if (hint) hint.textContent = 'Half day: one date, 0.5 leave. The day stays Present — no lateness or overtime is counted. Needs a check-in that day.';
  } else {
    to.disabled = false;
    if (hint) hint.textContent = 'No time cutoff applies to you. Emergency counts toward the rider\'s same-day allowance.';
  }
}
async function submitApplyLeave() {
  const btn = document.getElementById('leaveSubmitBtn');
  const type = (document.getElementById('leaveType') || {}).value || 'planned';
  const payload = {
    user_id: document.getElementById('leaveUser').value,
    leave_start_date: document.getElementById('leaveFrom').value,
    // Half day = single date; the To field is disabled then, so send From.
    leave_end_date: type === 'half_day' ? document.getElementById('leaveFrom').value : document.getElementById('leaveTo').value,
    note: document.getElementById('leaveNote').value,
    leave_type: type
  };
  if (!payload.user_id || !payload.leave_start_date || !payload.leave_end_date) { alert('Pick a person and both dates.'); return; }
  btn.disabled = true; btn.textContent = 'Approving…';
  try {
    const res = await fetch('/attendance/apply-leave', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify(payload)
    });
    const json = await res.json();
    if (json.success) {
      closeApplyLeave();
      alert(json.message || 'Leave approved.');
      if (typeof loadAttendanceForDate === 'function') loadAttendanceForDate();
    } else {
      // A balance may not go below zero (Sep-2026 ruling). There is no override any more —
      // the message tells the manager to raise the quota first, which is a deliberate act.
      alert(json.message || 'Could not apply the leave.');
    }
  } catch (e) {
    alert('Could not apply the leave. Please try again.');
  } finally {
    if (!btn.disabled) { btn.textContent = 'Approve leave'; }
    else { btn.disabled = false; btn.textContent = 'Approve leave'; }
  }
}

// Summary strip above the table: counts of riders needing a meter reading and
// company-bike riders flagged for overnight use today. Hidden when all clear.
// Effective overnight grace for a row: per-rider override (sent on the row) → global default.
function rowGrace(r) {
  const cfg = window.attConfig || {};
  if (r && r.overnight_grace_km != null && r.overnight_grace_km !== '') return Number(r.overnight_grace_km);
  return Number(cfg.overnight_grace_km != null ? cfg.overnight_grace_km : 30);
}
// ⭐⭐ ONE BRAIN for "the machine moved overnight" (Sep-2026). Three places asked this — the
//   summary strip, the meter-issues banner and the row chip — and each re-derived it, so they
//   could disagree with one another and with the red meter-gap verdict beside them.
//   `prev_meter_end` is now the MACHINE's own last reading (server-side `closingBaseline()`),
//   which is why this can finally be a single test.
// ⭐ A HANDOVER DAY IS NOT JUDGED. The baseline may be the outgoing rider's, so the difference
//   is not the incoming rider's night — the same rule `continuity()` and the ⛽ no-meter chip
//   already apply. Silence here, and the banner says why.
// Returns {over, grace, label, vtype} or null.
function rowOvernightFlag(r) {
  if (!r || Number(r.company_bike) !== 1) return null;
  if (r.transfer_day) return null;
  if (r.prev_meter_end == null || r.prev_meter_end === '') return null;
  if (r.meter_start == null || r.meter_start === '') return null;
  const grace = rowGrace(r);
  const over = Number(r.meter_start) - Number(r.prev_meter_end);
  if (!(over > grace)) return null;
  return { over: Math.round(over), grace: Math.round(grace),
           label: r.prev_meter_label || null, vtype: r.prev_meter_vtype || null };
}
// The chip's glyph follows the MACHINE the baseline came from, not is_company.
function overnightIcon(f) { return (f && f.vtype === 'van') ? '🚚' : '🏍'; }
// "− EDN-198's last reading 20,186" when the registry named the machine, else the old wording.
function overnightBasis(r, f, esc) {
  const e = esc || (s => s);
  const whose = (f && f.label) ? (e(f.label) + "'s last reading ") : 'prev end ';
  return 'start ' + e(r.meter_start) + ' − ' + whose + e(r.prev_meter_end)
       + (r.prev_meter_date ? ' (' + e(r.prev_meter_date) + ')' : '');
}
// ⭐ ONE BRAIN for "missed his meter" (Sep-2026). The server's day_checks verdict
//   knows everything this old client-side pair-test did not: the owner's
//   meter_required exemption, the registry excusal (no machine that day), the
//   HANDOVER day (excused for BOTH riders, like the month column), and meter_home
//   as a valid close. Before this, the summary chip and the row chip accused a man
//   whose ⛽ tick — two inches away, from day_checks — was green. The local rule
//   survives ONLY as a fallback for a row without day_checks.
function rowMissedMeter(r) {
  const dc = r && r.day_checks;
  if (dc && Array.isArray(dc.chips)) {
    return dc.chips.some(c => c && c.label === 'no meter');
  }
  const isRider = r.role_name && String(r.role_name).toLowerCase().includes('rider');
  const hasPair = r.meter_start != null && r.meter_end != null;
  return !!(isRider && r.logout_time && !hasPair);
}
function renderMeterAttention(data) {
  const el = document.getElementById('meterAttention');
  if (!el) return;
  let missing = 0, overnight = 0;
  (data || []).forEach(r => {
    if (!r.login_time) return;
    if (rowMissedMeter(r)) missing++;
    if (rowOvernightFlag(r)) overnight++;
  });
  const chips = [];
  if (missing > 0) chips.push(`<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#FEF3C7;color:#92400E;border:1px solid #FDE68A;">⛽ ${missing} rider${missing>1?'s':''} without a meter reading</span>`);
  if (overnight > 0) chips.push(`<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:700;background:#FEE2E2;color:#B91C1C;border:1px solid #FCA5A5;">🏍 ${overnight} overnight bike flag${overnight>1?'s':''}</span>`);
  el.innerHTML = chips.join('');
  el.classList.toggle('hidden', chips.length === 0);
}

// Top alert banner for company-bike meter issues on the selected day (info only, no actions):
//   • overnight grace exceeded (start meter − yesterday's end > grace), and/or
//   • no meter reading recorded (checked in with no start, or out with no end).
// Fully guarded — needs company_bike; non-bike / non-came riders never appear and never error.
function renderGraceBreachBanner(data) {
  const card = document.getElementById('graceBreachCard');
  const body = document.getElementById('graceBreachBody');
  if (!card || !body) return;
  const has = v => v != null && v !== '';
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  const rows = [];
  (data || []).forEach(r => {
    if (Number(r.company_bike) !== 1) return;
    const checkedIn = has(r.login_time), checkedOut = has(r.logout_time);
    if (!checkedIn && !checkedOut) return; // didn't work today
    const chips = [];
    // overnight grace exceeded — one shared test, and it names the machine it measured.
    const onf = rowOvernightFlag(r);
    if (onf) {
      chips.push('<span style="font-size:12px;font-weight:700;color:#B91C1C;background:#FEE2E2;border:1px solid #FCA5A5;border-radius:6px;padding:1px 7px;">' + overnightIcon(onf) + ' ' + onf.over + ' km overnight</span>' +
        '<span style="font-size:11.5px;color:#6B7280;margin-left:6px;">' + overnightBasis(r, onf, esc) + ' · grace ' + onf.grace + ' km</span>');
    }
    // no meter reading recorded
    const missStart = checkedIn && !has(r.meter_start);
    const missEnd = checkedOut && !has(r.meter_end);
    if (missStart || missEnd) {
      const which = (missStart && missEnd) ? 'start & end' : (missStart ? 'start' : 'end');
      // ⭐ ON A HANDOVER DAY THIS IS NOT THE RIDER FORGETTING (Aug-22 2026).
      //   When the machine changed hands, the outgoing rider has no closing reading and the
      //   incoming one no start — and NEITHER can fix it from his phone, because the bike has
      //   already moved on. Saying "no meter reading" invites a manager to chase someone who
      //   cannot act. `transfer_day` is already computed server-side (AttendanceController sets
      //   it from VehicleResolver::isTransferDay), so name the cause and the one thing that
      //   actually closes it: the handover meter on the Bikes page.
      chips.push(r.transfer_day
        ? '<span style="font-size:12px;font-weight:700;color:#0F766E;background:#CCFBF1;border:1px solid #5EEAD4;border-radius:6px;padding:1px 7px;">🔁 bike changed hands — ' + which + ' meter missing</span>'
          + '<span style="font-size:11.5px;color:#6B7280;margin-left:6px;">record the handover meter on the Bikes page to close it</span>'
        : '<span style="font-size:12px;font-weight:700;color:#92400E;background:#FEF3C7;border:1px solid #FDE68A;border-radius:6px;padding:1px 7px;">⛽ no meter reading (' + which + ' missing)</span>');
    }
    // U5 — morning breaches: start typed at the office (skipped home) / reading doesn't
    // match last night within the tolerance. Same banner, same "manager sees it" purpose.
    const wj = r.work_journey;
    if (wj && checkedIn && wj.state === 'no_home_start') {
      // Say WHERE only when it was measured (wj.place); otherwise state the fact the column
      // actually carries — not recorded at home — instead of asserting "office".
      const npWhere = wj.place ? wj.place.label : 'not recorded at home';
      chips.push('<span style="font-size:12px;font-weight:700;color:#92400E;background:#FEF3C7;border:1px solid #FDE68A;border-radius:6px;padding:1px 7px;">🏠 no home start' + (wj.recorded_at ? ' (' + npWhere + ' ' + wj.recorded_at + ')' : '') + '</span>');
    }
    if (wj && wj.continuity && wj.continuity.breach) {
      // ⭐ SAY WHOSE READING THE BASELINE IS. This line used to print
      //   "vs last closing 26441 ()" — a bare pair of brackets, because the machine-keyed
      //   path returns prev_date = null while the blade printed it unconditionally. The
      //   empty brackets were the only visible clue that the number came from a different
      //   source than the reader assumed, and they said nothing. Name the machine when we
      //   know it, print the date only when there is one, and drop the brackets entirely
      //   when neither is available.
      const cparts = [];
      if (wj.continuity.label) cparts.push(esc(wj.continuity.label));
      if (wj.continuity.prev_date) cparts.push(esc(wj.continuity.prev_date));
      const cwhere = cparts.length ? ' (' + cparts.join(' · ') + ')' : '';
      chips.push('<span style="font-size:12px;font-weight:700;color:#B91C1C;background:#FEE2E2;border:1px solid #FCA5A5;border-radius:6px;padding:1px 7px;">🏠 meter gap ' + (wj.continuity.gap > 0 ? '+' : '') + wj.continuity.gap + ' km</span>' +
        '<span style="font-size:11.5px;color:#6B7280;margin-left:6px;">morning ' + esc(r.meter_start) + ' vs last closing ' + esc(wj.continuity.prev) + cwhere + '</span>');
    }
    if (chips.length) rows.push({name: r.fullname || r.name || 'Rider', chips});
  });

  if (!rows.length) { card.style.display = 'none'; return; }
  document.getElementById('graceBreachCount').textContent = rows.length;
  body.innerHTML = rows.map(r =>
    '<div style="display:flex;align-items:baseline;gap:8px;padding:6px 0;border-top:1px solid #FECACA;flex-wrap:wrap;">' +
      '<span style="font-size:13px;font-weight:600;color:#111827;min-width:130px;">' + esc(r.name) + '</span>' +
      '<span style="display:flex;flex-direction:column;gap:3px;">' + r.chips.map(c => '<span>' + c + '</span>').join('') + '</span>' +
    '</div>'
  ).join('');
  card.style.display = 'block';
}

// Where the rider checked out (manager view). Gray = office, green = at a customer,
// amber ⚠ = that delivery point was away from the address's saved pin, amber = elsewhere.
// checkoutChipSpan returns just the coloured <span> (for inline use on the OUT line);
// checkoutChip wraps it in a spaced block (kept for any legacy caller).
function checkoutChipSpan(info) {
  if (!info || !info.status) return '';
  let bg, fg, bd, text, title = '';
  if (info.status === 'office') {
    if (info.office_exception) {
      // Non-bike rider who delivered but came back to the office — an exception, flag amber.
      bg = '#FEF3C7'; fg = '#92400E'; bd = '#FDE68A'; text = '⇢ At office';
      title = 'Checked out at the office after delivering — he came back (exception)';
    } else {
      bg = '#F3F4F6'; fg = '#4B5563'; bd = '#E5E7EB'; text = '⇢ ' + info.label;
    }
  } else if (info.status === 'delivery') {
    if (info.pin_away) {
      bg = '#FEF3C7'; fg = '#92400E'; bd = '#FDE68A';
      text = '⇢ ' + info.label + (info.pin_distance_m != null ? ' · ' + info.pin_distance_m + 'm off pin' : '');
      title = 'Checked out at the delivery point, ' + (info.pin_distance_m || '?') + ' m from the saved address pin';
    } else {
      // Checking out at a delivery point is NORMAL — gray/info, not green. One color language:
      // color is spent on things that need attention (amber/red), never on the expected case.
      bg = '#F3F4F6'; fg = '#4B5563'; bd = '#E5E7EB'; text = '⇢ ' + info.label;
      title = 'Checked out at the delivery point';
    }
  } else {
    bg = '#FEF3C7'; fg = '#92400E'; bd = '#FDE68A'; text = '⇢ ' + info.label;
  }
  const esc = s => String(s).replace(/</g, '&lt;').replace(/"/g, '&quot;');
  return `<span title="${esc(title)}" style="display:inline-flex;align-items:center;font-size:11px;font-weight:600;color:${fg};background:${bg};border:1px solid ${bd};border-radius:5px;padding:1px 6px;white-space:normal;">${esc(text)}</span>`;
}
function checkoutChip(info) {
  const span = checkoutChipSpan(info);
  return span ? `<div style="margin-top:3px;">${span}</div>` : '';
}

// ── The "Day timeline" cell: three labeled lines so IN / OUT / HOME are never
//    confused. Each event carries its OWN time and place. The HOME line (company-bike
//    going-home journey) surfaces the ETA, the actual arrival, and when the meter was
//    entered — data that used to hide inside the bypass modal.
function tlLabel(t, color) {
  return `<span style="display:inline-block;width:38px;flex:none;font-size:9.5px;font-weight:800;letter-spacing:.05em;color:${color || '#9CA3AF'};">${t}</span>`;
}
// The Day-timeline cell now defaults to a COMPACT line (IN → OUT + "✓ day clear" or the day's
// issue chips). A clean day stays one line; a day with issues opens to the full START/IN/OUT/HOME
// automatically. Either way a "details ▸ / collapse ▴" toggle is always there — nothing is lost.
function dayTimelineCell(r, lateBy, locationBadge) {
  const full = buildFullTimeline(r, lateBy, locationBadge);
  // Absent / not-needed rows never worked → just the minimal full line, no toggle.
  if (!r.login_time && !r.logout_time) return full;

  const dc = r.day_checks;
  const clean = !!(dc && dc.is_clean);
  const uid = r.user_id;
  const compact = buildCompactTimeline(r, lateBy);
  const toggle = (label) => `<button type="button" onclick="toggleTimeline(${uid})" style="background:none;border:none;padding:0;margin-left:8px;font-size:10.5px;color:#9CA3AF;cursor:pointer;">${label}</button>`;
  return `<div id="tlc_${uid}" style="display:${clean ? 'block' : 'none'};">${compact}${toggle('details ▸')}</div>` +
         `<div id="tlf_${uid}" style="display:${clean ? 'none' : 'block'};">${full}<div style="margin-top:2px;">${toggle('collapse ▴')}</div></div>`;
}

// Toggle a single row between the compact and the full timeline (no re-render).
function toggleTimeline(uid) {
  const c = document.getElementById('tlc_' + uid), f = document.getElementById('tlf_' + uid);
  if (!c || !f) return;
  const showFull = f.style.display === 'none';
  f.style.display = showFull ? 'block' : 'none';
  c.style.display = showFull ? 'none' : 'block';
}

// Compact one-liner: IN → OUT, then either "✓ day clear" or the day's issue chips.
function buildCompactTimeline(r, lateBy) {
  const dc = r.day_checks;
  const inT = r.login_time
    ? `<span style="font-size:13px;font-weight:600;color:${lateBy.isLate ? '#B91C1C' : '#111827'};">${r.login_time}</span>`
    : '<span style="font-size:13px;color:#D1D5DB;">–</span>';
  const outT = r.logout_time
    ? `<span style="font-size:13px;font-weight:600;color:#111827;">${r.logout_time}</span>`
    : '<span style="font-size:12px;color:#9CA3AF;">still in</span>';
  const chips = (dc && dc.is_clean)
    ? '<span style="font-size:11px;font-weight:700;color:#15803D;background:#E9F7EE;border:1px solid #BFE8CC;border-radius:5px;padding:0 6px;">✓ day clear</span>'
    : (dc && dc.chips || []).map(c => {
        const [bg, fg, bd] = c.tone === 'red' ? ['#FDECEC', '#B91C1C', '#F5C6C6'] : ['#FDF3E3', '#B45309', '#F3DFB9'];
        return `<span style="font-size:11px;font-weight:700;color:${fg};background:${bg};border:1px solid ${bd};border-radius:5px;padding:0 6px;">${c.label}</span>`;
      }).join(' ');
  return `<div style="display:flex;align-items:baseline;gap:6px;flex-wrap:wrap;">${inT}<span style="color:#D1D5DB;">→</span>${outT}${chips ? ' ' + chips : ''}</div>`;
}

function buildFullTimeline(r, lateBy, locationBadge) {
  const lines = [];

  // START — U5 morning meter at home + the ride-to-work deadline. Live line while he is
  // still on the way; after check-in it collapses into the IN line's verdict.
  const start = startTimelineLine(r);
  if (start) lines.push(start);

  // IN — check-in time + WHERE he checked in (the old separate column, now labeled in
  // place) + the shift-late tag + the ride-in ETA verdict for bike riders.
  if (r.login_time) {
    const lateTag = lateBy.isLate
      ? `<span style="font-size:10.5px;font-weight:600;color:#B45309;">late ${lateBy.duration}</span>` : '';
    const spot = locationBadge && locationBadge.html && locationBadge.type !== 'no_location'
      ? locationBadge.html
      : '<span style="font-size:10.5px;color:#9CA3AF;">no location</span>';
    lines.push(
      `<div style="display:flex;align-items:baseline;gap:6px;flex-wrap:wrap;">${tlLabel('IN')}` +
      `<span style="font-size:13px;font-weight:600;color:${lateBy.isLate ? '#B91C1C' : '#111827'};">${r.login_time}</span>` +
      `${spot}${lateTag}${inEtaVerdict(r)}</div>`);
  } else {
    lines.push(`<div style="display:flex;align-items:baseline;gap:3px;">${tlLabel('IN')}<span style="font-size:13px;color:#D1D5DB;">–</span></div>`);
  }

  // OUT — checkout time + WHERE he checked out (the chip that used to float unlabeled).
  if (r.logout_time) {
    const co = checkoutChipSpan(r.checkout_info);
    lines.push(
      `<div style="display:flex;align-items:baseline;gap:6px;flex-wrap:wrap;">${tlLabel('OUT')}` +
      `<span style="font-size:13px;font-weight:600;color:#111827;">${r.logout_time}</span>${co}${countedNote(r)}</div>`);
  } else if (r.login_time) {
    lines.push(`<div style="display:flex;align-items:baseline;gap:3px;">${tlLabel('OUT')}<span style="font-size:12px;color:#9CA3AF;">still in</span></div>`);
  }

  // HOME — company-bike going-home journey (only when one is armed).
  const home = homeTimelineLine(r);
  if (home) lines.push(home);

  return lines.join('');
}

// U5 — the START line: ALWAYS visible for a company-bike rider who has a morning journey, so
// "what ETA was he given vs what did he do" reads on the row itself, not only inside the bypass
// modal. Live (riding/overdue), the office-typed "no home start", and the clean completed home
// start all render; a meter-continuity breach appends its chip to whichever line shows.
function startTimelineLine(r) {
  const wj = r.work_journey;
  if (!wj) return '';
  const nm = String(r.fullname || '').replace(/"/g, '&quot;').replace(/'/g, "\\'");
  const open = `onclick="openHomeValve(${r.user_id}, '${nm}')" title="Click to manage (Rider bypasses)"`;
  const wrap = (inner) =>
    `<div ${open} style="cursor:pointer;display:flex;align-items:baseline;gap:5px;flex-wrap:wrap;">${tlLabel('START', '#0E7490')}` +
    `<span style="font-size:11px;display:inline-flex;gap:5px;flex-wrap:wrap;align-items:baseline;">${inner}</span></div>`;
  const sep = '<span style="color:#D1D5DB;">·</span>';
  const contChip = (wj.continuity && wj.continuity.breach)
    ? `${sep}<span style="color:#B91C1C;font-weight:700;" title="Morning reading vs last closing meter (${wj.continuity.prev}) differs by ${wj.continuity.gap} km">meter gap ${wj.continuity.gap > 0 ? '+' : ''}${wj.continuity.gap} km</span>` : '';
  // "due 11:25 (11.8 km · ETA 23m + 10m)" — the ETA plus the grace so the deadline math is visible.
  const dueMeta = () => {
    const meta = [];
    if (wj.distance_km != null) meta.push(wj.distance_km + ' km');
    if (wj.eta_min != null) meta.push('ETA ' + wj.eta_min + 'm' + (wj.buffer_min ? ' + ' + wj.buffer_min + 'm' : ''));
    return 'due ' + (wj.expected_by || '—') + (meta.length ? ' (' + meta.join(' · ') + ')' : '');
  };
  const homeTime = wj.recorded_at ? '<b>' + wj.recorded_at + '</b>' : '✓';

  if (wj.state === 'riding' || wj.state === 'overdue') {
    const late = wj.state === 'overdue' ? `${sep}<span style="color:#B91C1C;font-weight:700;">${wj.minutes_late ? '+' + wj.minutes_late + 'm ' : ''}overdue</span>` : '';
    return wrap(
      `<span style="color:#065F46;font-weight:600;">${homeTime} 🏠 at home</span>${sep}` +
      `<span style="color:#6B7280;">riding — ${dueMeta()}</span>${late}${contChip}`);
  }
  if (wj.state === 'no_home_start') {
    // WHERE, as a measurement. This used to read "typed at office", which the system never
    // actually verified — it printed the same words when the phone sent no fix at all, and a
    // rider standing in his own house got told he was at the office (Waseem, Jul-25).
    // wj.place is null on rows recorded before the hardening shipped: then we say only what
    // meter_start_source really means — not recorded at home — and claim nothing about where.
    const how = wj.place
      ? `${wj.place.label}${wj.recorded_at ? ' ' + wj.recorded_at : ''}`
      : (wj.recorded_at ? `not recorded at home · ${wj.recorded_at}` : 'no start meter');
    return wrap(`<span style="color:#B45309;font-weight:700;">no home start</span>${sep}<span style="color:#6B7280;">${how}</span>${contChip}`);
  }
  // Home start recorded (checked in on time / late, OR not yet checked in with no timer) —
  // ALWAYS show it: time + place + the deadline he was given.
  const noTimer = !wj.expected_by;
  const dueTxt = noTimer ? '<span style="color:#9CA3AF;">riding (no timer)</span>' : `<span style="color:#6B7280;">${dueMeta()}</span>`;
  return wrap(`<span style="color:#065F46;font-weight:600;">${homeTime} 🏠 at home</span>${sep}${dueTxt}${contChip}`);
}

// U5 — small verdict on the IN line for a bike rider who armed a morning deadline:
// "✓ before 07:45" or "+12m past 07:45". Empty for everyone else.
function inEtaVerdict(r) {
  const wj = r.work_journey;
  if (!wj || !r.login_time || !wj.expected_by) return '';
  if (wj.state === 'arrived_late') {
    return `<span style="font-size:10.5px;font-weight:700;color:#B91C1C;" title="Arrived after the ride-to-work deadline">+${wj.minutes_late || '?'}m past ${wj.expected_by}</span>`;
  }
  if (wj.state === 'arrived_on_time') {
    return `<span style="font-size:10.5px;font-weight:600;color:#15803D;" title="Arrived within the ride-to-work deadline">✓ before ${wj.expected_by}</span>`;
  }
  return '';
}

// The HOME line: "due 19:03 (15.6 km · ETA 34m) · arrived 18:58 ✓ · meter 18:53".
// Clickable → the same Rider-bypasses / valve modal as the meter chip. Returns '' for
// non-bike riders and anyone without an armed journey.
function homeTimelineLine(r) {
  const hj = r.home_journey;
  if (!hj) return '';

  const meta = [];
  if (hj.distance_km != null) meta.push(hj.distance_km + ' km');
  if (hj.eta_min != null) meta.push('ETA ' + hj.eta_min + 'm');
  const dueTxt = 'due ' + (hj.expected_by || '—') + (meta.length ? ' (' + meta.join(' · ') + ')' : '');

  let arrTxt, arrColor;
  if (hj.arrived_at) {
    const late = hj.minutes_late ? ` +${hj.minutes_late}m` : '';
    const confirmed = (hj.arrival_source && hj.arrival_source !== 'manual') ? ' ✓' : '';
    arrTxt = 'arrived ' + hj.arrived_at + confirmed + late;
    arrColor = hj.minutes_late ? '#B45309' : '#15803D';
  } else if (hj.state === 'late_locked') {
    arrTxt = 'not home · locked' + (hj.minutes_late ? ' +' + hj.minutes_late + 'm' : '');
    arrColor = '#B91C1C';
  } else if (hj.state === 'unlocked') {
    arrTxt = 'unlocked by manager';
    arrColor = '#B45309';
  } else {
    arrTxt = 'riding…';
    arrColor = '#6B7280';
  }

  const parts = [
    `<span style="color:#6B7280;">${dueTxt}</span>`,
    `<span style="color:${arrColor};font-weight:600;">${arrTxt}</span>`,
  ];
  if (hj.has_meter) {
    parts.push(`<span style="color:#166534;font-weight:600;">meter ${hj.recorded_at ? hj.recorded_at : '✓'}</span>`);
  }
  const sep = '<span style="color:#D1D5DB;">·</span>';
  const nm = String(r.fullname || '').replace(/"/g, '&quot;').replace(/'/g, "\\'");
  return `<div onclick="openHomeValve(${r.user_id}, '${nm}')" title="Click to manage the home meter / bypass"` +
    ` style="cursor:pointer;display:flex;align-items:baseline;gap:5px;flex-wrap:wrap;margin-top:1px;">${tlLabel('HOME', '#6D28D9')}` +
    `<span style="font-size:11px;display:inline-flex;gap:5px;flex-wrap:wrap;align-items:baseline;">${parts.join(sep)}</span></div>`;
}

function getMeterFlags(record) {
  // Store EVERY row (keyed by user_id — absent rows have no attendance_id) so the
  // always-visible 🔓 action button can open the Rider-bypasses modal for any row.
  (window.__attRows = window.__attRows || {})[record.user_id] = record;
  if (!record.login_time) return '';
  const cfg = window.attConfig || {meter_gps_warn_km: 10, overnight_grace_km: 30};
  const chips = [];
  const isRider = record.role_name && String(record.role_name).toLowerCase().includes('rider');

  const chip = (bg, fg, bd, txt, title) => `<span style="display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:5px;font-size:11px;font-weight:600;background:${bg};color:${fg};border:1px solid ${bd};" title="${title}">${txt}</span>`;

  // Same one-brain rule as the summary strip — see rowMissedMeter(). The old
  // inline pair-test accused riders day_checks excuses (handover day, no machine,
  // owner-exempted, meter_home close) and the two verdicts sat on the same row.
  if (rowMissedMeter(record)) {
    chips.push(chip('#FEF3C7', '#92400E', '#FDE68A', '⛽ no meter', 'Checked out without a meter reading'));
  }

  const meter = record.meter_distance;
  const compare = (record.road_distance_km != null) ? record.road_distance_km
                : (record.gps_distance != null ? record.gps_distance : null);
  if (meter != null && compare != null) {
    const diff = Math.abs(Number(meter) - Number(compare));
    if (diff > Number(cfg.meter_gps_warn_km)) {
      chips.push(chip('#FEF9C3', '#854D0E', '#FDE047', `⚠ ${Math.round(diff)} km off`, `Meter ${meter} km vs tracked ${Math.round(compare)} km — differ by ${Math.round(diff)} km; reading may be inaccurate`));
    }
  }

  {
    // Same shared test as the strip and the banner — see rowOvernightFlag().
    const onf = rowOvernightFlag(record);
    if (onf) {
      const whose = onf.label ? `${onf.label}'s last reading` : "yesterday's end";
      chips.push(chip('#FEE2E2', '#B91C1C', '#FCA5A5', `${overnightIcon(onf)} +${onf.over} km overnight`, `Start meter ${record.meter_start} − ${whose} ${record.prev_meter_end} = ${onf.over} km overnight (grace ${onf.grace} km)`));
    }
  }

  // Company-bike going-home meter (the manager valve chip; clickable).
  const hjChip = getHomeJourneyChip(record);
  if (hjChip) chips.push(hjChip);

  // Generic "Rider bypasses" chip — the ONE discoverable entry for unlocking a stuck
  // rider (checkout-from-anywhere; home-meter valve rides along in the same modal).
  const bpChip = getBypassChip(record, isRider);
  if (bpChip) chips.push(bpChip);

  if (!chips.length) return '';
  return `<div class="flex flex-wrap gap-1 mt-1">${chips.join('')}</div>`;
}

// The COUNTED end of a bypassed day (owner ruling Aug-8), as an inline OUT-line note:
// "counted 23:05 · last delivery". The checkout timestamp is when the manager's valve was
// used, not when the work ended — this shows the manager what the hours/OT actually read to.
function countedNote(r) {
  const cc = r.checkout_counted;
  if (!cc) return '';
  if (cc.time) {
    return `<span style="font-size:10.5px;font-weight:700;color:#B45309;" title="Checkout used a manager bypass, so its timestamp isn't when the work ended — this day's hours and overtime are counted up to the last delivered order at ${cc.time}.">⏱ counted ${cc.time} · last delivery</span>`;
  }
  return `<span style="font-size:10.5px;font-weight:700;color:#B45309;" title="Checkout used a manager bypass and no orders were delivered this day — nothing past the shift is counted.">⏱ nothing counted · no deliveries</span>`;
}

// Bypass chip: while a rider is still checked in → a subtle 🔓 opener (amber while a
// checkout unlock is active); after a bypassed checkout → an audit chip.
function getBypassChip(record, isRider) {
  if (!record.attendance_id) return '';
  const nm = String(record.fullname || '').replace(/"/g, '&quot;').replace(/'/g, "\\'");
  const open = `onclick="openHomeValve(${record.user_id}, '${nm}')"`;
  const cu = record.checkout_unlock;
  const base = 'cursor:pointer;display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:5px;font-size:11px;font-weight:600;';
  if (record.login_time && !record.logout_time && isRider) {
    if (cu && cu.active) {
      return `<span ${open} style="${base}background:#FEF3C7;color:#92400E;border:1px solid #FDE68A;" title="Checkout unlocked until ${cu.until} by ${cu.by_name || 'manager'} — click to manage">🔓 checkout unlocked</span>`;
    }
    return `<span ${open} style="${base}background:#F3F4F6;color:#6B7280;border:1px solid #E5E7EB;" title="Rider bypasses — allow checkout from anywhere (forgot to check out), unlock the home meter, etc.">🔓 bypass</span>`;
  }
  if (cu && cu.used) {
    // When the OT engine re-based the day, the chip says WHAT it counted to — the manager
    // shouldn't have to reconcile "bypassed" with hours/OT numbers that don't match the OUT time.
    const cc = record.checkout_counted;
    const extra = cc ? (cc.time ? ` · counted ${cc.time}` : ' · nothing counted') : '';
    const ccTitle = cc
      ? (cc.time ? ` — day counted up to the last delivered order at ${cc.time}, not the checkout time`
                 : ' — no orders delivered, nothing past the shift is counted')
      : '';
    return `<span ${open} style="${base}background:#FEF3C7;color:#92400E;border:1px solid #FDE68A;" title="Checked out via manager bypass (${cu.by_name || 'manager'}${cu.reason ? ': ' + String(cu.reason).replace(/"/g, '&quot;') : ''})${ccTitle}">🔓 bypassed checkout${extra}</span>`;
  }
  return '';
}

function homeBreachLabel(b) {
  return {time: 'reached home late', location: 'location not confirmed', both: 'late + location not confirmed'}[b] || '';
}

// A clickable chip summarising a company-bike rider's going-home meter state; opens the valve.
function getHomeJourneyChip(record) {
  const hj = record.home_journey;
  if (!hj) return '';
  const late = hj.minutes_late ? ` +${hj.minutes_late}m` : '';
  let bg, fg, bd, txt, title;
  if (hj.has_meter) {
    if (hj.breach || hj.state === 'completed_late') {
      bg = '#FEF3C7'; fg = '#92400E'; bd = '#FDE68A';
      txt = '🏍 home meter (late)';
      title = `Home meter ${hj.meter_home} km${hj.breach ? ' · bypass: ' + homeBreachLabel(hj.breach) : ''}${hj.reason ? ' · ' + hj.reason : ''}`;
    } else {
      bg = '#DCFCE7'; fg = '#166534'; bd = '#BBF7D0';
      txt = '🏍 home meter ✓'; title = `Home meter ${hj.meter_home} km — on time`;
    }
  } else if (hj.state === 'late_locked') {
    bg = '#FEE2E2'; fg = '#B91C1C'; bd = '#FCA5A5';
    txt = `🔒 home late — locked${late}`; title = 'Reached home late — meter entry is locked. Click to unlock or enter it.';
  } else if (hj.state === 'unlocked') {
    bg = '#FEF3C7'; fg = '#92400E'; bd = '#FDE68A';
    txt = '🔓 unlocked'; title = 'You opened meter entry for the rider. Click to manage.';
  } else if (hj.state === 'arrived_pending_meter') {
    bg = '#DBEAFE'; fg = '#1E40AF'; bd = '#BFDBFE';
    txt = '🏠 home · meter pending'; title = 'Arrived home on time; meter not typed yet. Click to enter it for him.';
  } else {
    bg = '#F3F4F6'; fg = '#374151'; bd = '#E5E7EB';
    txt = '🏍 riding home'; title = `Expected home by ${hj.expected_by || '-'}. Click to manage.`;
  }
  const nm = String(record.fullname || '').replace(/"/g, '&quot;').replace(/'/g, "\\'");
  return `<span onclick="openHomeValve(${record.user_id}, '${nm}')" style="cursor:pointer;display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:5px;font-size:11px;font-weight:600;background:${bg};color:${fg};border:1px solid ${bd};" title="${title}">${txt}</span>`;
}

// ⛽ / 📡 verdict ticks for the Day cell. Reads day_checks (server) — the tick's colour IS the
// verdict and the tick IS the link to its modal. Meter tick is hidden for riders who carry no
// meter (management with no readings); GPS tick shows gray "–" when there's nothing to judge.
function meterGpsTicks(record, nmEsc) {
  const dc = record.day_checks;
  if (!dc) return ''; // didn't work today
  const esc = s => String(s == null ? '' : s).replace(/"/g, '&quot;');
  const btn = (fn, title, bg, fg, bd, txt) =>
    `<button type="button" onclick="${fn}" title="${esc(title)}" style="margin-left:7px;vertical-align:middle;cursor:pointer;font-size:11px;font-weight:700;border-radius:5px;padding:1px 6px;background:${bg};color:${fg};border:1px solid ${bd};">${txt}</button>`;
  const out = [];

  // ⛽ meter — hidden when the rider carries no meter at all (e.g. management).
  const meterRelevant = record.meter_start != null || record.meter_end != null || /rider/i.test(record.role_name || '');
  if (meterRelevant) {
    const fn = `showMeterDetail(${record.user_id}, '${nmEsc}', '${record.attendance_date}')`;
    out.push(dc.meter_ok
      ? btn(fn, 'Meter clean — both readings, right place, no bypass. Click for the day’s meter story.', '#E9F7EE', '#15803D', '#BFE8CC', '⛽ ✓')
      : btn(fn, (dc.meter_issues || []).join(' · ') || 'Meter needs review', '#FDF3E3', '#B45309', '#F3DFB9', '⛽ ⚠'));
  }
  // 📡 GPS
  const gfn = `showGpsAudit(${record.user_id}, '${nmEsc}', '${record.attendance_date}')`;
  out.push(dc.gps_ok === true
    ? btn(gfn, 'GPS on while on duty (' + dc.gps_worst + '%). Commute is not judged. Click for phases.', '#E9F7EE', '#15803D', '#BFE8CC', '📡 ✓')
    : dc.gps_ok === false
    ? btn(gfn, dc.gps_note || 'GPS gaps while on duty', '#FDF3E3', '#B45309', '#F3DFB9', '📡 ⚠')
    : btn(gfn, 'Not enough on-duty GPS to judge', '#F3F4F6', '#9CA3AF', '#E5E7EB', '📡 –'));
  return out.join('');
}

/**
 * ⭐ Get distance badge HTML showing meter, road distance (primary), and GPS distance
 */
// One-line meter cell (the approved mockup): "127 km 📷📷 details".
// The meter reading is what the manager scans daily; road distance, GPS trail,
// coverage %, No-GPS state etc. all live behind the "details" link (the GPS-audit
// modal already renders the full meter/road/GPS comparison + trail stats).
function getDistanceBadge(record) {
  if (!record.login_time) {
    return '<span style="color:#D1D5DB;font-size:12px;">–</span>';
  }
  const hasMeter = record.meter_distance !== null && record.meter_distance !== undefined;
  const hasRoad = record.road_distance_km !== null && record.road_distance_km !== undefined;
  const hasGps = record.gps_distance !== null && record.gps_distance !== undefined;
  const gpsReadings = record.gps_readings_count || 0;

  const nmEsc = (record.fullname || '').replace(/'/g, "\\'");
  // ⛽ / 📡 VERDICT TICKS — the tick IS the link. Green ✓ = nothing to open; amber ⚠ = worth a
  // click; gray – = can't judge. Verdict comes from day_checks (server, one source of truth), so
  // the tick can never disagree with the modal it opens.
  const details = meterGpsTicks(record, nmEsc);

  /**
   * 🏍️🚚 TWO MACHINES IN ONE DAY (owner ask, 10-Sep-2026).
   *
   * ⚠⚠ `t_ops_attendance` holds ONE meter pair per rider-day, and this cell printed the
   *    difference between them as "N km". On a day the rider arrives on his own bike and
   *    takes the van out, those two readings are of DIFFERENT ODOMETERS — a real row on
   *    this system reads 27,751 → 17,259 — so the number is not a distance at all, and
   *    nothing on the row said so. The rider's own phone has said it since Aug-27.
   *
   * ⭐ The reading STAMPS answer it (`meter_machines`, server-side). Each machine's real
   *   kilometres live in the petrol modal, which reads the same legs the rider's app does —
   *   so the marker points there rather than inventing a second number here.
   * ⚠ `split` is true only when BOTH readings are stamped and they differ. One stamped and
   *   one not is "we cannot say", which must keep rendering exactly as it always has.
   */
  const mm = record.meter_machines;
  if (mm && mm.split) {
    const esc2 = s => String(s == null ? '' : s).replace(/"/g, '&quot;');
    const num = v => (v == null ? '–' : Number(v).toLocaleString());
    const tip = `Start ${num(record.meter_start)} on ${esc2(mm.start_label || 'one machine')}`
      + ` · close ${num(record.meter_end)} on ${esc2(mm.end_label || 'another')}.`
      + ' Two different odometers, so the difference between them is not a distance.'
      + ' Open ⛽ for the day, or the rider’s petrol modal for each machine’s own km.';
    return `<span title="${tip}" style="font-size:12px;font-weight:600;color:#B45309;`
      + `background:#FFFBEB;border:1px solid #FDE68A;border-radius:5px;padding:1px 6px;`
      + `vertical-align:middle;">🏍️🚚 two machines</span>${getMeterPhotoIcons(record)}${details}`;
  }

  /* ⭐ …and on an ordinary day, say WHICH machine the pair belongs to — in the tooltip, so
       the one-line cell the mockup approved is unchanged for anyone not asking. */
  const oneLabel = (mm && !mm.split) ? (mm.start_label || mm.end_label) : null;

  if (hasMeter) {
    const t = oneLabel ? ` title="${String(oneLabel).replace(/"/g, '&quot;')} — this machine’s odometer"` : '';
    return `<span${t} style="font-size:13px;font-weight:600;color:#111827;vertical-align:middle;">${record.meter_distance} km</span>${getMeterPhotoIcons(record)}${details}`;
  }
  if (hasRoad) {
    // No meter reading — fall back to the tracked road distance, clearly marked.
    return `<span title="Tracked road distance (no meter reading)" style="font-size:12px;color:#6B7280;vertical-align:middle;">🛰 ${record.road_distance_km} km</span>${details}`;
  }
  if (hasGps) {
    return `<span title="GPS straight-line distance (no meter reading)" style="font-size:12px;color:#6B7280;vertical-align:middle;">🛰 ${record.gps_distance} km</span>${details}`;
  }
  if (!record.logout_time && gpsReadings > 0) {
    // Day still running — distance settles at checkout.
    return `<span style="font-size:11px;color:#9CA3AF;vertical-align:middle;">tracking…</span>${details}`;
  }
  if (record.logout_time) {
    // Day ended with nothing recorded — audit link only.
    return `<span style="font-size:12px;color:#D1D5DB;vertical-align:middle;">–</span>${details}`;
  }
  return '<span style="color:#D1D5DB;font-size:12px;">–</span>';
}

// ⭐ ONE filter pass that COMPOSES status + location + vehicle — a row shows only if it passes
// ALL active filters (the old separate functions overwrote each other; the last one won). Group
// headers hide whenever any filter is active so no header floats above an empty group.
function applyRowFilters() {
  const status = (document.getElementById('statusFilter') || {}).value || 'all';
  const location = (document.getElementById('locationFilter') || {}).value || 'all';
  const bike = (document.getElementById('bikeFilter') || {}).value || 'all';
  const anyActive = status !== 'all' || location !== 'all' || bike !== 'all';

  document.querySelectorAll('#attBody tr[data-status]').forEach(row => {
    const okStatus = status === 'all' || row.getAttribute('data-status') === status;
    const okLoc = location === 'all' || row.getAttribute('data-location') === location;
    const okBike = bike === 'all' || (bike === 'bike' && row.getAttribute('data-bike') === '1');
    row.style.display = (okStatus && okLoc && okBike) ? '' : 'none';
  });
  document.querySelectorAll('#attBody tr.att-group').forEach(h => { h.style.display = anyActive ? 'none' : ''; });
}
// Back-compat aliases (any other caller / older cached handler keeps working).
function filterTableByStatus() { applyRowFilters(); }
function filterByLocation() { applyRowFilters(); }

function updateSummaryCards(data) {
  let present = 0, onTime = 0, late = 0, overtime = 0, onLeave = 0, absent = 0;
  
  data.forEach(r => {
    // HALF-DAY with a check-in: Present + On time, never Late/Overtime/On-leave
    // (0.5 already sits in the yearly leave counter; owner's rule).
    if (r.is_half_day && r.login_time) {
      present++;
      onTime++;
    }
    // Check if on leave (approved or pending)
    else if (r.leave_request_id && (r.leave_status === 'approved' || r.leave_status === 'pending')) {
      onLeave++;
    }
    // If has attendance
    else if (r.login_time) {
      present++;
      // ⭐ Late / Overtime follow the PAYROLL rule exactly: the frozen per-date snapshot
      // (late_minutes / overtime_minutes) — the SAME source the row and the salary calc use
      // (ShiftResolutionService::sumLateOvertimeMinutes). This makes the tile count agree with
      // the rows and with payroll; the naive login<=shift compare could disagree (grace, a
      // shift changed after the fact, or the frozen snapshot differing from today's shift).
      // Half-day already handled above (never late / never OT). Fall back to the shift compare
      // only when the snapshot is absent (older cached response).
      const tLate = (r.late_minutes != null)
        ? (Number(r.late_raw_minutes != null ? r.late_raw_minutes : r.late_minutes) > 0)   // late DAY = raw
        : (r.login_time > ((r.expected_shift_start ? String(r.expected_shift_start).slice(0, 5) : null) || r.shift_start || '09:00'));
      if (tLate) { late++; } else { onTime++; }

      // Start-only shifts (no end) never count as overtime — don't invent 17:00.
      const tOt = (r.overtime_minutes != null)
        ? (Number(r.overtime_minutes) > 0)
        : (!!r.logout_time && !!r.shift_end && r.logout_time > r.shift_end);
      if (tOt) { overtime++; }
    }
    // Absent ONLY on a genuine working day. A no-login day that's a weekly off, a
    // public holiday, or before the rider's hire date is not an absence.
    else if ((r.day_kind || 'working') === 'working') {
      absent++;
    }
  });
  
  document.getElementById('cardPresent').textContent = present;
  document.getElementById('cardOnTime').textContent = onTime;
  document.getElementById('cardLate').textContent = late;
  document.getElementById('cardOvertime').textContent = overtime;
  document.getElementById('cardOnLeave').textContent = onLeave;
  document.getElementById('cardAbsent').textContent = absent;
}

// Smart quick add - handles both login and logout
function quickAddLogout(userId, userName) {
  try {
  if (blockSelfAttendanceEdit(userId)) return; // R8 self-edit block
  const user = allAttendanceData.find(u => u.user_id == userId);
  currentEditUserId = userId;
  currentEditDate = document.getElementById('tableDate').value;
  
  // Set current time as default
  const now = new Date();
  const hours = String(now.getHours()).padStart(2, '0');
  const minutes = String(now.getMinutes()).padStart(2, '0');
  const currentTime = `${hours}:${minutes}`;
  
  // Determine what to show based on current attendance
  const hasLogin = user && user.login_time;
  const hasLogout = user && user.logout_time;
  
  // Set user info
    const quickTimeUser = document.getElementById('quickTimeUser');
    const quickTimeDate = document.getElementById('quickTimeDate');
    const quickTimeUserInitial = document.getElementById('quickTimeUserInitial');
    
    if (!quickTimeUser || !quickTimeDate || !quickTimeUserInitial) {
      console.error('Missing modal user info elements!');
      alert('Error: Modal elements not found. Please refresh the page.');
      return;
    }
    
    quickTimeUser.textContent = userName;
    quickTimeDate.textContent = currentEditDate;
    quickTimeUserInitial.textContent = userName.charAt(0).toUpperCase();
  
  // Show appropriate sections
  const loginSection = document.getElementById('loginTimeSection');
  const logoutSection = document.getElementById('logoutTimeSection');
  const modalTitle = document.getElementById('quickTimeModalTitle');
    
    if (!loginSection || !logoutSection || !modalTitle) {
      console.error('Missing section elements!');
      alert('Error: Modal section elements not found. Please refresh the page.');
      return;
    }
  
  if (!hasLogin) {
    // No login yet - ask for login time
    currentTimeModalMode = 'login';
    loginSection.classList.remove('hidden');
    logoutSection.classList.add('hidden');
    document.getElementById('quickLoginTimeInput').value = currentTime;
    modalTitle.textContent = 'Mark Login Time';
  } else if (!hasLogout) {
    // Has login, no logout - ask for logout time
    currentTimeModalMode = 'logout';
    loginSection.classList.add('hidden');
    logoutSection.classList.remove('hidden');
    document.getElementById('quickLogoutTimeInput').value = currentTime;
    modalTitle.textContent = 'Mark Logout Time';
  } else {
    // Both exist - should not happen (use edit button)
    alert('Attendance already complete. Use edit button to modify.');
    return;
  }
  
  const modal = document.getElementById('quickTimeModal');
    
    if (!modal) {
      console.error('quickTimeModal not found!');
      alert('Error: Modal not found. Please refresh the page.');
      return;
    }
    
    // Open modal with elegant styling - ensure it's visible
  modal.classList.remove('hidden');
    // Apply minimal overlay styling only
    Object.assign(modal.style, {
      display: 'flex',
      position: 'fixed',
      top: '0',
      left: '0',
      right: '0',
      bottom: '0',
      zIndex: '9999',
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: 'rgba(0,0,0,0.5)',
      padding: '1rem'
    });
    // Ensure card stays compact and readable
    const card = document.getElementById('quickTimeCard');
    if (card) {
      Object.assign(card.style, {
        maxWidth: '520px',
        width: '100%',
        background: 'white',
        color: '#111827'
      });
    }
    
  modal.onclick = function(e) {
    if (e.target === modal) closeQuickTime();
  };
    
    console.log('Modal opened for user:', userId, userName);
    console.log('Variables set:', {currentEditUserId, currentEditDate, currentTimeModalMode});
  } catch (error) {
    console.error('Error in quickAddLogout:', error);
    alert('Error: ' + error.message);
  }
}

function closeQuickTime() {
  const modal = document.getElementById('quickTimeModal');
  if (modal) {
    modal.classList.add('hidden');
    // Reset inline overlay styles to avoid accumulation
    modal.removeAttribute('style');
  }
  currentEditUserId = null;
  currentEditDate = null;
  currentTimeModalMode = null;
}

// ⭐ ONE ANSWER TO "WHAT DOES THIS MACHINE LOOK LIKE" (4 Sep 2026). The registry's `vtype`
//   ('bike' | 'van') decides — never `is_company`, which is a different question (whose fuel
//   we buy) and drew a company BIKE as a van. Falls back to the bike when a payload predates
//   the field, which is the old behaviour for everything that is not a van.
function machineIcon(v) {
  return (v && String(v.vtype || '') === 'van') ? '🚚' : '🏍️';
}

// ---- Meter correction (R7c) ----
let meterEditAttId = null;
let meterEditSeq = 0;
function openMeterEdit(attendanceId, meterStart, meterEnd, name, userId) {
  meterEditAttId = attendanceId;
  const dateVal = document.getElementById('tableDate') ? document.getElementById('tableDate').value : '';
  document.getElementById('meterEditWho').textContent = (name || '') + ' · ' + dateVal;
  document.getElementById('meterEditStart').value = (meterStart != null) ? meterStart : '';
  document.getElementById('meterEditEnd').value = (meterEnd != null) ? meterEnd : '';

  // ⭐ Offer the machines he actually rode that day. Additive and fail-quiet: if the
  //   registry cannot answer, the block simply stays hidden and this modal behaves
  //   exactly as it always has.
  const wrap = document.getElementById('meterEditVehWrap');
  const sel  = document.getElementById('meterEditVehicle');
  const hint = document.getElementById('meterEditVehHint');
  wrap.style.display = 'none';
  sel.innerHTML = '<option value="">— leave as recorded —</option>';
  hint.textContent = '';
  const seq = ++meterEditSeq;
  if (userId && dateVal) {
    fetch('/orders/riders-map/fleet/vehicles/petrol-context?user_id=' + encodeURIComponent(userId)
          + '&date=' + encodeURIComponent(dateVal), { headers: { 'Accept': 'application/json' } })
      .then(r => r.json())
      .then(res => {
        if (seq !== meterEditSeq) return;               // last answer wins
        const list = (res && res.vehicles) ? res.vehicles : [];
        if (!list.length) return;
        // ⚠ This blade has no global escaper (every `esc` here is function-local), and a
        //   plate/nickname is manager-typed text going into innerHTML.
        const escV = s => String(s == null ? '' : s)
          .replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        sel.innerHTML = '<option value="">— leave as recorded —</option>'
          + list.map(v => '<option value="' + v.vehicle_id + '">'
              + machineIcon(v) + ' ' + escV(v.label)
              + (v.km != null ? ' · ' + v.km + ' km' : '') + '</option>').join('');
        wrap.style.display = 'block';
        hint.textContent = 'Naming the vehicle is what lets his own-bike petrol claim use these readings.';
      })
      .catch(() => {});
  }
  document.getElementById('meterEditModal').style.display = 'flex';
}
function closeMeterEdit() {
  document.getElementById('meterEditModal').style.display = 'none';
  meterEditAttId = null;
}
async function saveMeterEdit() {
  if (!meterEditAttId) return;
  const startRaw = document.getElementById('meterEditStart').value;
  const endRaw = document.getElementById('meterEditEnd').value;
  const payload = { attendance_id: meterEditAttId };
  if (startRaw !== '') payload.meter_start = parseInt(startRaw, 10);
  if (endRaw !== '') payload.meter_end = parseInt(endRaw, 10);
  // ⭐ Only when the manager actually named one — an untouched dropdown must leave
  //   whatever stamp the row already carries exactly as it is.
  const vehSel = document.getElementById('meterEditVehicle');
  if (vehSel && vehSel.value) payload.vehicle_id = parseInt(vehSel.value, 10);
  if (payload.meter_start == null && payload.meter_end == null) { alert('Enter a start or end meter value.'); return; }
  if (payload.meter_start != null && payload.meter_end != null && payload.meter_end < payload.meter_start) {
    if (!confirm('End meter is less than start meter. Save anyway?')) return;
  }
  const btn = document.getElementById('meterEditSaveBtn');
  btn.disabled = true; btn.textContent = 'Saving…';
  try {
    const res = await fetch('/attendance/update-meter', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify(payload)
    });
    const j = await res.json();
    if (j.success) {
      closeMeterEdit();
      if (typeof loadAttendanceForDate === 'function') loadAttendanceForDate();
    } else {
      alert(j.message || 'Could not update the meter.');
    }
  } catch (e) {
    alert('Could not update the meter.');
  } finally {
    btn.disabled = false; btn.textContent = 'Save';
  }
}

// ── RIDER BYPASSES — one modal for every unlock a manager can grant ───────────
// Sections show only when they apply: checkout-from-anywhere (still checked in),
// home-meter unlock / manager entry (company-bike journey). Opened from the 🔓/🏍 chips.
function openHomeValve(userId, name) {
  const rec = (window.__attRows || {})[userId];
  if (!rec) return;
  const hj = rec.home_journey || null;
  const wj = rec.work_journey || null;
  const cu = rec.checkout_unlock || null;
  window.__homeValveAttId = rec.attendance_id || null;
  window.__homeValveUserId = userId;
  const dateVal = document.getElementById('tableDate') ? document.getElementById('tableDate').value : '';
  document.getElementById('homeValveWho').textContent = (name || '') + (dateVal ? ' · ' + dateVal : '');

  const bits = [];
  if (!rec.login_time) {
    bits.push('Not checked in on this day.');
    if (!wj) {
      bits.push('Bypasses apply while someone is ON DUTY (unlock checkout from anywhere when they forgot to check out) or on a company-bike journey (morning check-in unlock, home-meter unlock).');
      bits.push('To fix a missed day, use ➕ / ✏️ (mark or edit times) instead.');
    }
  }
  // U5 — morning-start summary (bike riders): the meter-at-home, the ride-in deadline,
  // and how the arrival compared to it.
  if (wj) {
    if (wj.recorded_at && wj.source === 'home') {
      bits.push(`Start meter at home <b>${wj.recorded_at}</b>${wj.distance_km != null ? ' · ' + wj.distance_km + ' km to office' : ''}`);
    } else if (wj.recorded_at) {
      bits.push(`<span style="color:#B45309;font-weight:700;">No home start</span> — start meter typed at the office ${wj.recorded_at}`);
    } else if (rec.login_time) {
      bits.push('<span style="color:#B45309;font-weight:700;">No home start</span> — no start meter recorded');
    }
    if (wj.expected_by) {
      bits.push(`Due at office by <b>${wj.expected_by}</b>` +
        (wj.state === 'arrived_late' ? ` — <span style="color:#B91C1C;font-weight:700;">arrived +${wj.minutes_late || '?'}m late</span>`
          : wj.state === 'arrived_on_time' ? ' — <span style="color:#15803D;font-weight:700;">arrived on time ✓</span>'
          : wj.state === 'overdue' ? ` — <span style="color:#B91C1C;font-weight:700;">not arrived, +${wj.minutes_late || '?'}m over</span>`
          : ' — riding'));
    }
    if (wj.continuity && wj.continuity.breach) {
      bits.push(`<span style="color:#B91C1C;font-weight:700;">Meter gap ${wj.continuity.gap > 0 ? '+' : ''}${wj.continuity.gap} km</span> vs last closing ${wj.continuity.prev} (${wj.continuity.prev_date})`);
    }
    if (wj.checkin_unlock && wj.checkin_unlock.active) {
      bits.push(`Check-in unlock active until <b>${wj.checkin_unlock.until}</b> (${wj.checkin_unlock.by_name || 'manager'})`);
    }
  }
  if (rec.login_time && !rec.logout_time) bits.push(`Checked in <b>${String(rec.login_time).slice(0, 5)}</b> — still on duty`);
  if (rec.logout_time) bits.push(`Checked out <b>${String(rec.logout_time).slice(0, 5)}</b>`);
  /**
   * 🏍️🚚 WHICH MACHINE EACH READING IS OF. This modal is where a manager lands when a day
   * looks wrong, and on Rajab's 11-Sep it could not say the one thing that explained it:
   * the start is his own bike's and the close is the van's. The stamps are already on the
   * row (`meter_machines`), so this only stops throwing them away. Silent on a single-machine
   * day and on every unstamped row.
   */
  const mm2 = rec.meter_machines || null;
  if (mm2 && mm2.split) {
    const e2 = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    bits.push(`<span style="color:#B45309;font-weight:700;">🏍️🚚 Two machines</span> — started on `
      + `<b>${e2(mm2.start_label || 'one machine')}</b>, closed on <b>${e2(mm2.end_label || 'another')}</b>.`
      + ` The day has no single meter distance; each machine's own km are in ⛽ / the Vehicles page.`);
  }
  if (cu && cu.used) bits.push(`<span style="color:#92400E;font-weight:700;">Checked out via bypass</span> (${cu.by_name || 'manager'}${cu.reason ? ': “' + cu.reason + '”' : ''})`);
  if (hj) {
    if (hj.expected_by) bits.push(`Due home by <b>${hj.expected_by}</b>${hj.distance_km != null ? ' · ' + hj.distance_km + ' km' : ''}`);
    if (hj.arrived_at) bits.push(`Arrived <b>${hj.arrived_at}</b>${hj.arrival_source ? ' (' + hj.arrival_source + ')' : ''}`);
    else bits.push('Home arrival <b>not GPS-confirmed</b>');
    if (hj.minutes_late) bits.push(`<span style="color:#B91C1C;font-weight:700;">${hj.minutes_late} min late</span>`);
    if (hj.reason) bits.push(`Reason: “${hj.reason}”`);
    if (hj.breach) bits.push(`Bypass breach: <b>${homeBreachLabel(hj.breach)}</b>`);
  }
  // Blocked-checkout context — WHY he's stuck (the reason this modal usually gets opened):
  // he pressed OUT and the location/time rule refused it. Shown prominently at the top with
  // the reason, distance and a link that maps his spot vs the delivery. Same block the mobile
  // bypass sheet renders (shared server formatter).
  const ca = rec.checkout_attempt || null;
  if (ca) {
    const when = ca.at ? String(ca.at).slice(11, 16) : '';
    const tries = (ca.count && ca.count > 1) ? ' · tried ' + ca.count + '×' : '';
    const settled = !ca.still_open; // he has since checked out (via bypass or a valid spot)
    const mapLink = ca.maps_url
      ? ' · <a href="' + ca.maps_url + '" target="_blank" rel="noopener" style="color:#1D4ED8;font-weight:700;text-decoration:underline;">view on map ↗</a>'
      : '';
    bits.unshift(
      '<div style="background:' + (settled ? '#F3F4F6' : '#FEF3C7') + ';border:1px solid ' + (settled ? '#D1D5DB' : '#F59E0B') +
      ';border-radius:8px;padding:8px 10px;margin-bottom:8px;">' +
        '<span style="font-weight:700;color:' + (settled ? '#6B7280' : '#92400E') + ';">⚠ ' +
          (settled ? 'Was blocked at checkout' : 'Tried to check out but couldn’t') +
          (when ? ' · ' + when : '') + tries + '</span><br>' +
        '<span style="color:' + (settled ? '#6B7280' : '#92400E') + ';">' + (ca.headline || '') + '</span>' + mapLink +
      '</div>'
    );
  }
  document.getElementById('homeValveInfo').innerHTML = bits.join('<br>') || 'No activity today.';

  // U5 — Unlock check-in: only meaningful BEFORE check-in, for a bike rider. Harmless
  // while the lock config is off (the unlock simply never gets consulted).
  const ciSec = document.getElementById('homeValveCheckinSec');
  const ciActive = document.getElementById('homeValveCiActive');
  if (ciSec) {
    const bikeRow = !!(wj || hj || rec.company_bike == 1);
    if (!rec.login_time && bikeRow) {
      ciSec.style.display = 'block';
      document.getElementById('homeValveCiReason').value = '';
      if (wj && wj.checkin_unlock && wj.checkin_unlock.active) {
        ciActive.style.display = 'block';
        ciActive.textContent = `✓ Unlock active until ${wj.checkin_unlock.until} (${wj.checkin_unlock.by_name || 'manager'}). Unlocking again extends it.`;
      } else {
        ciActive.style.display = 'none';
      }
    } else {
      ciSec.style.display = 'none';
    }
  }

  // Checkout-from-anywhere section — while he's checked in and not out.
  const coSec = document.getElementById('homeValveCheckoutSec');
  const coActive = document.getElementById('homeValveCoActive');
  if (rec.login_time && !rec.logout_time) {
    coSec.style.display = 'block';
    document.getElementById('homeValveCoReason').value = '';
    if (cu && cu.active) {
      coActive.style.display = 'block';
      coActive.textContent = `✓ Unlock active until ${cu.until} (${cu.by_name || 'manager'}). Unlocking again extends it.`;
    } else {
      coActive.style.display = 'none';
    }
  } else {
    coSec.style.display = 'none';
  }

  // UNDO CHECKOUT — the mirror: only once he HAS checked out, and only for today or yesterday
  // (the server enforces the same window; hiding it just avoids offering a button that refuses).
  // Also hidden once he is genuinely home with his bike meter recorded by the geofence — the
  // ride home is over, so that is a new day rather than a reopened one.
  const undoSec = document.getElementById('homeValveUndoSec');
  if (undoSec) {
    const todayStr = new Date().toLocaleDateString('en-CA');           // local Y-m-d, not UTC
    const yStr = new Date(Date.now() - 86400000).toLocaleDateString('en-CA');
    const inWindow = !dateVal || dateVal === todayStr || dateVal === yStr;
    const homeDone = !!(hj && hj.has_meter && hj.arrival_source === 'geofence');
    if (rec.logout_time && rec.attendance_id && inWindow && !homeDone) {
      undoSec.style.display = 'block';
      document.getElementById('homeValveUndoReason').value = '';
      // Name what will actually be thrown away, so it is never a surprise.
      const losing = [];
      if (rec.meter_end != null && rec.meter_end !== '') losing.push('closing meter ' + rec.meter_end);
      if (rec.logout_time) losing.push('checkout ' + String(rec.logout_time).slice(0, 5));
      losing.push('checkout location', 'road distance', 'overtime');
      document.getElementById('homeValveUndoWhat').textContent = 'Will be cleared: ' + losing.join(' · ');
    } else {
      undoSec.style.display = 'none';
    }
  }

  // Home-meter sections — only for a company-bike journey.
  const done = document.getElementById('homeValveDone');
  const unlockSec = document.getElementById('homeValveUnlockSec');
  const enterSec = document.getElementById('homeValveEnterSec');
  if (hj && hj.has_meter) {
    done.style.display = 'block';
    done.textContent = `✓ Home meter recorded: ${hj.meter_home} km. Use the meter cell's edit to correct a value.`;
    unlockSec.style.display = 'none';
    enterSec.style.display = 'none';
  } else if (hj) {
    done.style.display = 'none';
    // Unlock is only meaningful when the phone is locked out (late). When unlocked/armed/arrived,
    // hide it — the rider can already record; the manager only needs the "enter for him" fallback.
    unlockSec.style.display = (hj.state === 'late_locked') ? 'block' : 'none';
    enterSec.style.display = 'block';
    document.getElementById('homeValveReason').value = '';
    document.getElementById('homeValveMeter').value = '';
    document.getElementById('homeValveEnterReason').value = '';
  } else {
    done.style.display = 'none';
    unlockSec.style.display = 'none';
    enterSec.style.display = 'none';
  }
  document.getElementById('homeValveModal').style.display = 'flex';
}

async function homeValveCheckoutUnlock() {
  const attId = window.__homeValveAttId;
  const reason = document.getElementById('homeValveCoReason').value.trim();
  if (!attId) return;
  if (!reason) { alert('Please enter a reason for unlocking checkout.'); return; }
  const btn = document.getElementById('homeValveCoBtn');
  btn.disabled = true; btn.textContent = 'Unlocking…';
  try {
    const res = await fetch('/attendance/checkout-unlock', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ attendance_id: attId, reason: reason })
    });
    const j = await res.json();
    if (j.success) { closeHomeValve(); if (typeof loadAttendanceForDate === 'function') loadAttendanceForDate(); }
    else alert(j.message || 'Could not unlock.');
  } catch (e) { alert('Could not unlock.'); }
  finally { btn.disabled = false; btn.textContent = '🔓 Unlock checkout'; }
}
// Put a rider who already checked out back ON DUTY. Destructive (his closing meter goes), so
// it confirms once — every other bypass here is additive and does not need to.
async function homeValveUndoCheckout() {
  const attId = window.__homeValveAttId;
  const reason = document.getElementById('homeValveUndoReason').value.trim();
  if (!attId) return;
  if (!reason) { alert('Please enter a reason for putting him back on duty.'); return; }
  if (!confirm('Put him back on duty?\n\nHis closing meter, checkout location, road distance and overtime for this day will be cleared. He will be asked for the meter again when he really checks out.')) return;
  const btn = document.getElementById('homeValveUndoBtn');
  btn.disabled = true; btn.textContent = 'Reopening…';
  try {
    const res = await fetch('/attendance/undo-checkout', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ attendance_id: attId, reason: reason })
    });
    const j = await res.json();
    if (j.success) {
      alert(j.message || 'He is back on duty.');
      closeHomeValve();
      if (typeof loadAttendanceForDate === 'function') loadAttendanceForDate();
    } else {
      alert(j.message || 'Could not reopen the day.');
    }
  } catch (e) { alert('Could not reopen the day.'); }
  finally { btn.disabled = false; btn.textContent = '↩ Undo checkout'; }
}

function closeHomeValve() {
  document.getElementById('homeValveModal').style.display = 'none';
  window.__homeValveAttId = null;
  window.__homeValveUserId = null;
}

// U5 — grant the morning check-in unlock. Keyed on USER (not attendance id): a rider
// blocked before check-in may have no attendance row yet; the server creates one to
// carry the unlock and checkIn() later fills it in.
async function homeValveCheckinUnlock() {
  const uid = window.__homeValveUserId;
  const reason = document.getElementById('homeValveCiReason').value.trim();
  if (!uid) return;
  if (!reason) { alert('Please enter a reason for unlocking check-in.'); return; }
  const btn = document.getElementById('homeValveCiBtn');
  btn.disabled = true; btn.textContent = 'Unlocking…';
  try {
    const res = await fetch('/attendance/checkin-unlock', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ user_id: uid, reason: reason })
    });
    const j = await res.json();
    if (j.success) { closeHomeValve(); if (typeof loadAttendanceForDate === 'function') loadAttendanceForDate(); }
    else alert(j.message || 'Could not unlock.');
  } catch (e) { alert('Could not unlock.'); }
  finally { btn.disabled = false; btn.textContent = '🔓 Unlock check-in'; }
}
async function homeValveUnlock() {
  const attId = window.__homeValveAttId;
  const reason = document.getElementById('homeValveReason').value.trim();
  if (!attId) return;
  if (!reason) { alert('Please enter a reason for unlocking.'); return; }
  const btn = document.getElementById('homeValveUnlockBtn');
  btn.disabled = true; btn.textContent = 'Unlocking…';
  try {
    const res = await fetch('/attendance/home-journey/unlock', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ attendance_id: attId, reason: reason })
    });
    const j = await res.json();
    if (j.success) { closeHomeValve(); if (typeof loadAttendanceForDate === 'function') loadAttendanceForDate(); }
    else alert(j.message || 'Could not unlock.');
  } catch (e) { alert('Could not unlock.'); }
  finally { btn.disabled = false; btn.textContent = '🔓 Unlock for rider'; }
}
async function homeValveEnter() {
  const attId = window.__homeValveAttId;
  const meterRaw = document.getElementById('homeValveMeter').value;
  const reason = document.getElementById('homeValveEnterReason').value.trim();
  if (!attId) return;
  if (meterRaw === '' || isNaN(parseInt(meterRaw, 10))) { alert('Enter the meter reading.'); return; }
  if (!confirm('Enter this meter for the rider and close his day?')) return;
  const btn = document.getElementById('homeValveEnterBtn');
  btn.disabled = true; btn.textContent = 'Saving…';
  try {
    const res = await fetch('/attendance/home-journey/enter-meter', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ attendance_id: attId, meter_home: parseInt(meterRaw, 10), reason: reason })
    });
    const j = await res.json();
    if (j.success) { closeHomeValve(); if (typeof loadAttendanceForDate === 'function') loadAttendanceForDate(); }
    else alert(j.message || 'Could not record the meter.');
  } catch (e) { alert('Could not record the meter.'); }
  finally { btn.disabled = false; btn.textContent = 'Save meter'; }
}

async function saveQuickTime() {
  // Validate required data
  if (!currentEditUserId) {
    console.error('Missing currentEditUserId');
    alert('Error: User ID is missing. Please try again.');
    return;
  }
  
  if (!currentEditDate) {
    console.error('Missing currentEditDate');
    alert('Error: Date is missing. Please try again.');
    return;
  }
  
  let payload = {
    user_id: currentEditUserId,
    attendance_date: currentEditDate
  };
  
  if (currentTimeModalMode === 'login') {
    const loginTime = document.getElementById('quickLoginTimeInput').value;
    if (!loginTime) {
      alert('Please enter a login time');
      return;
    }
    payload.login_time = loginTime;
  } else if (currentTimeModalMode === 'logout') {
    const logoutTime = document.getElementById('quickLogoutTimeInput').value;
    if (!logoutTime) {
      alert('Please enter a logout time');
      return;
    }
    payload.logout_time = logoutTime;
  }

  console.log('Saving attendance with payload:', payload);

  try {
    const res = await fetch('/attendance', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify(payload)
    });

    const json = await res.json();
    console.log('Server response:', json);
    
    if (json.success) {
      console.log(`${currentTimeModalMode === 'login' ? 'Login' : 'Logout'} recorded by user ID: ${window.currentUser.id} (${window.currentUser.name})`);
      closeQuickTime();
      loadAttendanceForDate();
    } else {
      alert('❌ Error: ' + (json.message || 'Failed to save time'));
    }
  } catch(e) {
    console.error('Error saving time', e);
    alert('❌ Error saving time');
  }
}

// Toggle summary period
async function toggleSummaryPeriod(period) {
  currentSummaryPeriod = period;
  
  // Update button styles
  const dayBtn = document.getElementById('btnDaySummary');
  const monthBtn = document.getElementById('btnMonthSummary');
  
  if (period === 'day') {
    dayBtn.classList.add('bg-blue-600', 'text-white');
    dayBtn.classList.remove('text-gray-600');
    monthBtn.classList.remove('bg-blue-600', 'text-white');
    monthBtn.classList.add('text-gray-600');
    
    // Update with current date data
    updateSummaryCards(allAttendanceData);
  } else {
    monthBtn.classList.add('bg-blue-600', 'text-white');
    monthBtn.classList.remove('text-gray-600');
    dayBtn.classList.remove('bg-blue-600', 'text-white');
    dayBtn.classList.add('text-gray-600');
    
    // Fetch month data
    await loadMonthSummary();
  }
}

async function loadMonthSummary() {
  const date = new Date(document.getElementById('tableDate').value);
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const startDate = `${year}-${month}-01`;
  const endDate = new Date(year, date.getMonth() + 1, 0).toISOString().split('T')[0];
  
  try {
    const res = await fetch(`/attendance/summary?start=${startDate}&end=${endDate}`);
    const json = await res.json();
    
    if (json.success) {
      document.getElementById('cardPresent').textContent = json.data.on_time + json.data.late;
      document.getElementById('cardOnTime').textContent = json.data.on_time;
      document.getElementById('cardLate').textContent = json.data.late;
      document.getElementById('cardOvertime').textContent = json.data.absent; // Reuse for now
    }
  } catch(e) {
    console.error('Error loading month summary', e);
  }
}

// Quick edit modal
// DB times carry seconds ("12:07:45") but <input type="time"> is minute-granular —
// feeding it seconds shows a stuck seconds segment and the backend expects H:i.
// So: populate as HH:MM, remember the originals, and only send changed fields.
let quickEditOriginal = { login: '', logout: '' };
function openQuickEdit(userId, userName, loginTime, logoutTime, attendanceDate) {
  if (blockSelfAttendanceEdit(userId)) return; // R8 self-edit block
  currentEditUserId = userId;
  currentEditDate = attendanceDate || document.getElementById('tableDate').value;

  document.getElementById('quickEditUser').textContent = userName;
  document.getElementById('quickEditDate').textContent = currentEditDate;
  quickEditOriginal.login = String(loginTime || '').slice(0, 5);
  quickEditOriginal.logout = String(logoutTime || '').slice(0, 5);
  document.getElementById('quickLoginTime').value = quickEditOriginal.login;
  document.getElementById('quickLogoutTime').value = quickEditOriginal.logout;
  quickEditSyncReason();

  const modal = document.getElementById('quickEditModal');
  modal.classList.remove('hidden');
  // Minimal overlay styling
  Object.assign(modal.style, {
    display: 'flex',
    position: 'fixed',
    top: '0',
    left: '0',
    right: '0',
    bottom: '0',
    zIndex: '9999',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(0,0,0,0.5)',
    padding: '1rem'
  });
  const editCard = document.getElementById('quickEditCard');
  if (editCard) {
    Object.assign(editCard.style, {
      maxWidth: '480px',
      width: '100%',
      background: 'white',
      color: '#111827'
    });
  }
  
  modal.onclick = function(e) {
    if (e.target === modal) closeQuickEdit();
  };
}

function closeQuickEdit() {
  const modal = document.getElementById('quickEditModal');
  if (modal) {
    modal.classList.add('hidden');
    modal.removeAttribute('style');
  }
  const rsn = document.getElementById('quickEditReason');
  if (rsn) rsn.value = '';
  const box = document.getElementById('quickEditReasonBox');
  if (box) box.style.display = 'none';
  currentEditUserId = null;
  currentEditDate = null;
}

// Phase 0 — reveal the reason box only when a RECORDED time is being overwritten.
// Adding a missing time is not an edit and must not grow a required field.
function quickEditOverwrites() {
  const login = String(document.getElementById('quickLoginTime').value || '');
  const logout = String(document.getElementById('quickLogoutTime').value || '');
  const out = [];
  if (quickEditOriginal.login && login && login !== quickEditOriginal.login) out.push('check-in');
  if (quickEditOriginal.logout && logout && logout !== quickEditOriginal.logout) out.push('checkout');
  return out;
}

function quickEditSyncReason() {
  const box = document.getElementById('quickEditReasonBox');
  const why = document.getElementById('quickEditReasonWhy');
  if (!box) return;
  const fields = quickEditOverwrites();
  box.style.display = fields.length ? 'block' : 'none';
  if (fields.length && why) {
    const pairs = [];
    if (fields.includes('check-in')) {
      pairs.push('check-in ' + quickEditOriginal.login + ' → ' + document.getElementById('quickLoginTime').value);
    }
    if (fields.includes('checkout')) {
      pairs.push('checkout ' + quickEditOriginal.logout + ' → ' + document.getElementById('quickLogoutTime').value);
    }
    why.textContent = pairs.join(' · ') + ' — kept with your reason, and this day’s overtime is recalculated from it.';
  }
}

document.addEventListener('DOMContentLoaded', function () {
  ['quickLoginTime', 'quickLogoutTime'].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', quickEditSyncReason);
  });
});

async function saveQuickEdit() {
  const loginTime = document.getElementById('quickLoginTime').value;
  const logoutTime = document.getElementById('quickLogoutTime').value;

  if (!loginTime && !logoutTime) {
    alert('Please enter at least one time');
    return;
  }

  // Only send what the user changed — the backend updates provided fields only,
  // so an untouched login keeps its stored seconds instead of being rewritten.
  const payload = {
    user_id: currentEditUserId,
    attendance_date: currentEditDate
  };
  if (loginTime !== quickEditOriginal.login) payload.login_time = loginTime || null;
  if (logoutTime !== quickEditOriginal.logout) payload.logout_time = logoutTime || null;
  if (!('login_time' in payload) && !('logout_time' in payload)) {
    closeQuickEdit();
    return;
  }

  // Phase 0 — an overwrite carries its reason. Asked for here so the manager never loses
  // his typing to a server refusal, and enforced server-side regardless.
  const overwrites = quickEditOverwrites();
  const reasonEl = document.getElementById('quickEditReason');
  if (overwrites.length) {
    quickEditSyncReason();
    const reason = String((reasonEl && reasonEl.value) || '').trim();
    if (!reason) {
      alert('Say why the ' + overwrites.join(' and ') + ' is being changed — the old time is kept with your reason.');
      if (reasonEl) reasonEl.focus();
      return;
    }
    payload.reason = reason;
  }

  try {
    const res = await fetch('/attendance', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify(payload)
    });

    const json = await res.json();
    if (json.success) {
      alert('✅ Attendance updated successfully!');
      closeQuickEdit();
      loadAttendanceForDate();
    } else if (json.reason_required) {
      // The server saw an overwrite the client did not (e.g. the row moved under us).
      const box = document.getElementById('quickEditReasonBox');
      if (box) box.style.display = 'block';
      alert('❌ ' + (json.message || 'A reason is required for this change.'));
      if (reasonEl) reasonEl.focus();
    } else {
      alert('❌ Error: ' + (json.message || 'Failed to update attendance'));
    }
  } catch(e) {
    console.error('Error updating attendance', e);
    alert('❌ Error updating attendance');
  }
}

// Utility functions
function calculateLateBy(loginTime, shiftStart) {
  if (!loginTime) {
    return { isLate: false, duration: '-' };
  }
  
  const shift = shiftStart || '09:00';
  if (loginTime <= shift) {
    return { isLate: false, duration: '-' };
  }
  
  const [lh, lm] = loginTime.split(':').map(Number);
  const [sh, sm] = shift.split(':').map(Number);
  const loginMinutes = lh * 60 + lm;
  const shiftMinutes = sh * 60 + sm;
  const diff = loginMinutes - shiftMinutes;
  
  const hours = Math.floor(diff / 60);
  const mins = diff % 60;
  
  if (hours > 0) {
    return { isLate: true, duration: `${hours}h ${mins}m` };
  }
  return { isLate: true, duration: `${mins}m` };
}

function calculateOvertime(logoutTime, shiftEnd, loginTime) {
  if (!logoutTime || !loginTime) {
    return { hasOvertime: false, duration: '-' };
  }
  
  const end = shiftEnd || '17:00';
  if (logoutTime <= end) {
    return { hasOvertime: false, duration: '-' };
  }
  
  const [lh, lm] = logoutTime.split(':').map(Number);
  const [eh, em] = end.split(':').map(Number);
  const logoutMinutes = lh * 60 + lm;
  const endMinutes = eh * 60 + em;
  const diff = logoutMinutes - endMinutes;
  
  if (diff <= 0) {
    return { hasOvertime: false, duration: '-' };
  }
  
  const hours = Math.floor(diff / 60);
  const mins = diff % 60;
  
  if (hours > 0) {
    return { hasOvertime: true, duration: `${hours}h ${mins}m` };
  }
  return { hasOvertime: true, duration: `${mins}m` };
}

function calculateHours(login, logout) {
  if (!login || !logout) return '-';
  const [lh, lm] = login.split(':').map(Number);
  const [oh, om] = logout.split(':').map(Number);
  const loginM = lh * 60 + lm;
  const logoutM = oh * 60 + om;
  let diff = logoutM - loginM;
  // Logout past midnight rolls to the next day — the same rule the OT engine
  // (OvertimeService) applies. A 10:52 → 00:05 day used to show no hours at all.
  if (diff < 0) diff += 1440;
  const h = Math.floor(diff / 60);
  const m = diff % 60;
  return `${h}h ${m}m`;
}

// Shift manager
let shiftModalOpen = false;

function showShiftManager() {
  console.log('showShiftManager called, current state:', shiftModalOpen);
  
  if (shiftModalOpen) {
    console.log('Modal already open, ignoring duplicate call');
    return;
  }
  
  const modal = document.getElementById('shiftModal');
  console.log('Shift modal element:', modal);
  
  if (!modal) {
    alert('❌ Error: Shift modal not found!');
    console.error('shiftModal element does not exist');
    return;
  }
  
  shiftModalOpen = true;
  loadShiftData();

  // Portalize modal to body to avoid clipping/stacking issues
  try {
    if (modal.parentElement !== document.body) {
      document.body.appendChild(modal);
    }
  } catch (e) {
    console.warn('Could not portalize shiftModal:', e);
  }

  // Enforce centered modal overlay (not full screen)
  modal.classList.remove('hidden');
  Object.assign(modal.style, {
    display: 'flex',
    position: 'fixed',
    top: '0',
    left: '0',
    right: '0',
    bottom: '0',
    zIndex: '99999',
    backgroundColor: 'rgba(0,0,0,0.5)',
    overscrollBehavior: 'contain'
  });
  // Lock background scroll while modal open
  window.__prevBodyOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
  console.log('Shift modal opened successfully');
  
  // Close on background click
  modal.onclick = function(e) {
    if (e.target === modal) {
      closeShiftManager();
    }
  };
  // Ensure card keeps reasonable width even if utility classes conflict
  const card = document.getElementById('shiftModalCard');
  if (card) {
    card.style.width = 'min(92vw, 900px)';
    card.style.maxHeight = '85vh';
  }
}

// Open shift manager for specific user (from attendance row action button)
function openShiftManagerForUser(userId, userName) {
  console.log('Opening shift manager for user:', userId, userName);
  // Redirect to shifts page with user filter in URL
  window.location.href = `/shifts?user=${userId}&name=${encodeURIComponent(userName)}`;
}

function closeShiftManager() {
  const modal = document.getElementById('shiftModal');
  if (modal) {
    modal.classList.add('hidden');
    modal.style.display = 'none';
    shiftModalOpen = false;
    console.log('Shift modal closed');
    // Restore background scroll
    document.body.style.overflow = '';
  }
}

async function loadShiftData() {
  try {
    const res = await fetch('/users/all');
    const json = await res.json();
    const users = json.data || [];
    
    const shiftList = document.getElementById('shiftList');
    shiftList.innerHTML = `
      <div class="grid gap-2 text-[11px] text-gray-500 uppercase tracking-wide pb-1 border-b border-gray-200" style="grid-template-columns: 1fr 110px 110px 70px;">
        <div>Employee</div>
        <div class="text-center">Start</div>
        <div class="text-center">End</div>
        <div class="text-right">Action</div>
      </div>
    ` + users.map(u => `
      <div class="py-1.5 border-b border-gray-100">
        <div class="grid gap-2 items-center" style="grid-template-columns: 1fr 110px 110px 70px;">
          <div class="flex items-center min-w-0">
            <div class="truncate">
              <span class="font-medium text-gray-800">${u.fullname || 'User #' + u.id}</span>
              <span class="ml-1 text-[10px] text-gray-500">${u.role_name || ''}</span>
            </div>
          </div>
          <div class="flex justify-center text-xs text-gray-600 font-medium">${u.shift_start || '—'}</div>
          <div class="flex justify-center text-xs text-gray-600 font-medium">${u.shift_end || '—'}</div>
          <div class="flex justify-end">
            <button onclick="saveShift(${u.id}, ${JSON.stringify(u.fullname || ('User #' + u.id))})" class="px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 text-xs whitespace-nowrap">Change</button>
          </div>
        </div>
      </div>
    `).join('');
  } catch(e) {
    console.error('Failed to load shift data', e);
  }
}

/**
 * ⚰ 7-Sep-2026 — this used to POST raw times to `/riders/shift`, the legacy ungated door.
 *    That route is gone. ONE ENGINE: it now opens the same "Change shift" popup the planner
 *    and this page's own rows already use, which posts to `/shifts/assign` and therefore
 *    obeys the ladder, the own-shift rule, the allowed-shift list and the approval queue.
 *
 * ⚠ The two time columns above are now READ-ONLY. They are the legacy
 *   `t_ops_rider_profile` values, kept only as the resolution fallback for anyone never
 *   migrated; nothing writes them any more. A shift is a TEMPLATE now, not two loose times,
 *   which is why there is nothing here to type into.
 */
function saveShift(userId, userName) {
  if (typeof openShiftChange !== 'function') {
    alert('Open the Shift Planner to change shifts.');
    return;
  }
  openShiftChange({ userId: userId, userName: userName, onSaved: () => loadShiftData() });
}

// Employee Details Modal Functions
async function showEmployeeDetails(userId, fullname, fromDate, startDate, endDate, rangeLabel, initialFilter) {
  console.log('showEmployeeDetails called:', { userId, fullname, fromDate, startDate, endDate, initialFilter });

  // W1 — remember who the popup is for (leave tile → yearly leaves popup) and reset/seed
  // the chip filter (W3 passes one so a Month-tab count opens pre-filtered).
  window.__empDetailCtx = { userId: userId, name: fullname || '' };
  window.__empDetailFilter = initialFilter || null;

  // If fromDate is null/undefined, use current selected date from date picker or today
  if (!fromDate || fromDate === 'null' || fromDate === 'undefined') {
    const dateInput = document.getElementById('attendanceDate');
    fromDate = dateInput ? dateInput.value : new Date().toISOString().split('T')[0];
  }
  
  console.log('Using fromDate:', fromDate);
  
  const modal = document.getElementById('employeeDetailsModal');
  const body = document.getElementById('employeeDetailsBody');
  
  if (!modal || !body) {
    console.error('Modal elements not found');
    return;
  }

  // Show modal - Force display with !important
  modal.classList.remove('hidden');
  modal.style.cssText = 'display: flex !important; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 10000; background-color: rgba(0, 0, 0, 0.5); align-items: center; justify-content: center; padding: 1rem;';
  
  // Debug: Check modal dimensions and visibility
  const rect = modal.getBoundingClientRect();
  const computedStyle = window.getComputedStyle(modal);
  console.log('Modal should be visible now', { 
    display: modal.style.display, 
    computedDisplay: computedStyle.display,
    visibility: computedStyle.visibility,
    opacity: computedStyle.opacity,
    zIndex: computedStyle.zIndex,
    dimensions: { width: rect.width, height: rect.height, top: rect.top, left: rect.left },
    classList: modal.classList.toString() 
  });
  
  // Set loading state
  body.innerHTML = '<tr><td colspan="9" style="padding: 20px; text-align: center; color: #6b7280;">Loading employee details...</td></tr>';
  
  // Set employee name
  document.getElementById('detailsEmployeeName').textContent = fullname || 'Employee';
  
  try {
    // Fetch employee details with order stats. An explicit start/end (Month tab) scopes the
    // whole detail to the selected month; without it the endpoint uses the rolling 30 days.
    let detailUrl = `/attendance/employee-details?user_id=${userId}&from_date=${fromDate}`;
    if (startDate && endDate) detailUrl += `&start_date=${startDate}&end_date=${endDate}`;
    const res = await fetch(detailUrl, {
      headers: { 'Accept': 'application/json' }
    });
    
    const json = await res.json();
    
    if (!json.success) {
      body.innerHTML = `<tr><td colspan="9" style="padding: 20px; text-align: center; color: #dc2626;">Error: ${json.message || 'Failed to load data'}</td></tr>`;
      return;
    }
    
    employeeDetailsData = json;
    const emp = json.employee;
    const records = json.daily_records;
    
    // Update header with error handling
    try {
      const startDate = emp.date_range && emp.date_range.start ? new Date(emp.date_range.start + 'T00:00:00') : new Date();
      const endDate = emp.date_range && emp.date_range.end ? new Date(emp.date_range.end + 'T00:00:00') : new Date();
      
      const dateRange = startDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + 
                       ' - ' + 
                       endDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    document.getElementById('detailsDateRange').textContent = dateRange + ' (' + (rangeLabel || 'Last 30 Days') + ')';
    } catch(e) {
      console.error('Error formatting date range:', e);
      document.getElementById('detailsDateRange').textContent = 'Last 30 Days';
    }
    
    // Use backend-calculated statistics
    console.log('Employee stats from backend:', {
      working_days: emp.working_days,
      present_days: emp.present_days,
      on_leave_days: emp.on_leave_days,
      absent_days: emp.absent_days,
      calculation: `${emp.working_days} working days - ${emp.present_days} present - ${emp.on_leave_days} on leave = ${emp.absent_days} absent`
    });
    
    // Update stats with backend-calculated values
    document.getElementById('detailsStatPresent').textContent = emp.present_days || 0;
    document.getElementById('detailsStatLate').textContent = emp.late_days || 0;
    // Overtime is shown the way it is EARNED and PAID OUT: hours beyond the target, and the
    // whole bonus leave days those hours have already bought. A bare day-count told a manager
    // nothing about what the rider gets — and disagreed with the Month tab.
    (function () {
      const otMin = Number(emp.overtime_minutes) || 0;
      const bonus = Number(emp.overtime_bonus_leaves) || 0;
      const el = document.getElementById('detailsStatOT');
      el.textContent = otMin > 0 ? fmtMinsShort(otMin) : '0';
      const sub = document.getElementById('detailsStatOTBonus');
      if (sub) {
        sub.textContent = bonus > 0 ? `= ${bonus} bonus day${bonus === 1 ? '' : 's'}` : '';
        sub.style.display = bonus > 0 ? 'block' : 'none';
      }
      const tile = document.getElementById('empTileOvertime');
      if (tile) {
        const per = Number(emp.overtime_minutes_per_bonus) || 540;
        tile.title = otMin > 0
          ? `${fmtMinsShort(otMin)} worked beyond the daily target over ${emp.overtime_days || 0} day(s). `
            + `${fmtMinsShort(per)} of overtime = 1 bonus leave day, so this earns ${bonus}. Click to see the days.`
          : 'No overtime beyond the daily target this period.';
      }
    })();
    document.getElementById('detailsStatOnLeave').textContent = emp.on_leave_days || 0;
    document.getElementById('detailsStatAbsent').textContent = emp.absent_days || 0;
    document.getElementById('detailsStatHours').textContent = (emp.total_hours || 0) + 'h';
    document.getElementById('detailsStatOrders').textContent = emp.total_orders_delivered || 0;
    
    // Render table (via the filterable renderer — W1: the stat tiles filter these rows)
    renderEmployeeDetailRows();
    console.log('Employee details loaded successfully');

  } catch(e) {
    console.error('Error loading employee details:', e);
    body.innerHTML = `<tr><td colspan="12" style="padding: 20px; text-align: center; color: #dc2626;">Error loading data: ${e.message}</td></tr>`;
  }
}

// W1 — chip filter state for the employee-details popup. Click a stat tile to filter the
// rows; click it again to clear. Pure client-side (rows are cached in employeeDetailsData).
function setEmpDetailFilter(f) {
  window.__empDetailFilter = (window.__empDetailFilter === f) ? null : f;
  renderEmployeeDetailRows();
}
function openEmpDetailLeaves() {
  const ctx = window.__empDetailCtx || {};
  if (ctx.userId && typeof openLeaveActions === 'function') openLeaveActions(ctx.userId, ctx.name || '');
}
function empDetailRowMatches(day, f) {
  if (!f) return true;
  const hasLogin = day.login_time && day.login_time !== '-';
  if (f === 'present') return !!hasLogin; // includes late + ½ day — every day he was in
  if (f === 'late') return day.status === 'late' || (hasLogin && day.late_minutes > 0 && day.status !== 'half_day');
  if (f === 'overtime') return hasLogin && day.overtime_minutes > 0;
  if (f === 'absent') return day.status === 'absent' || (!hasLogin && !day.leave_request_id && day.status !== 'on_leave' && day.status !== 'not_needed');
  if (f === 'on_leave') return day.status === 'on_leave' || day.status === 'half_day'; // month Leave-count click
  return true;
}
function renderEmployeeDetailRows() {
  const body = document.getElementById('employeeDetailsBody');
  if (!body || !employeeDetailsData) return;
  const all = employeeDetailsData.daily_records || [];
  const filter = window.__empDetailFilter || null;

  // Tile highlight + hint
  const tiles = { present: 'empTilePresent', late: 'empTileLate', overtime: 'empTileOvertime', absent: 'empTileAbsent' };
  Object.keys(tiles).forEach(k => {
    const el = document.getElementById(tiles[k]);
    if (el) el.style.boxShadow = (filter === k) ? '0 0 0 2px #2563EB' : 'none';
  });
  const hint = document.getElementById('empDetailFilterHint');
  if (hint) {
    if (filter) {
      hint.style.display = 'block';
      hint.innerHTML = `Showing only <b>${filter}</b> days — <a href="#" onclick="setEmpDetailFilter('${filter}'); return false;" style="color:#2563EB;text-decoration:underline;">show all</a>`;
    } else { hint.style.display = 'none'; }
  }

  if (!all.length) {
    body.innerHTML = '<tr><td colspan="12" style="padding: 20px; text-align: center; color: #6b7280;">No records found for this period</td></tr>';
    return;
  }
  // Meter gaps must compare against the FULL day sequence (records are date-DESC), so
  // precompute each row's previous-day meter end BEFORE filtering.
  all.forEach((day, i) => {
    day.__prevMeterEnd = (i < all.length - 1 && all[i + 1] && all[i + 1].meter_end) ? all[i + 1].meter_end : null;
    // …and WHICH MACHINE that previous close was of, so the overnight gap is never measured
    // between two different odometers (the morning after a van day: bike 7,6xx vs van 75,4xx).
    day.__prevEndVid = (i < all.length - 1 && all[i + 1] && all[i + 1].meter_machines && all[i + 1].meter_machines.end_id)
      ? all[i + 1].meter_machines.end_id : null;
  });
  const records = all.filter(d => empDetailRowMatches(d, filter));
  if (!records.length) {
    body.innerHTML = `<tr><td colspan="12" style="padding: 20px; text-align: center; color: #6b7280;">No ${filter} days in this period.</td></tr>`;
    return;
  }

  body.innerHTML = records.map((day, index) => {
      const date = new Date(day.attendance_date + 'T00:00:00');
      const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
      const formattedDate = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
      const rowBg = index % 2 === 0 ? '#f9fafb' : 'white';

      // Prefer the backend-provided status (covers the filled-in absent/leave rows);
      // fall back to the login/leave heuristic for older responses.
      const isOnLeave = day.leave_request_id && (day.leave_status === 'approved' || day.leave_status === 'pending');
      const isPresent = day.login_time && day.login_time !== '-';
      const lateRaw = Number(day.late_raw_minutes != null ? day.late_raw_minutes : day.late_minutes) || 0;
      const lateWaived = Number(day.late_waived_minutes) || 0;
      const isLate = isPresent && lateRaw > 0;
      const st = day.status || (isOnLeave ? 'on_leave' : (isLate ? 'late' : (isPresent ? 'present' : 'absent')));
      const statusMap = {
        present:  ['Present',  '#dcfce7', '#166534'],
        late:     ['Late',     '#fee2e2', '#991b1b'],
        absent:   ['Absent',   '#fef2f2', '#991b1b'],
        on_leave: ['On Leave', '#dbeafe', '#1e40af'],
        half_day: ['🌗 ½ day', '#ede9fe', '#6d28d9'],
        off:      ['Off',      '#f3f4f6', '#6b7280'],
        holiday:  ['Holiday',  '#f3f4f6', '#6b7280'],
        not_needed: ['Not needed', '#e0e7ff', '#3730a3'],
      };
      const [status, statusBg, statusColor] = statusMap[st] || statusMap.absent;

      const loginTime = day.login_time || '-';
      const logoutTime = day.logout_time || '-';
      // Bypassed checkout (owner ruling Aug-8): show WHAT the day was counted to, right
      // under the logout it re-bases — hours + overtime on this row read from that end.
      const cc = day.checkout_counted;
      const countedSub = cc
        ? (cc.time
            ? `<div style="font-size:10px;font-weight:700;color:#B45309;margin-top:2px;white-space:nowrap;" title="Checkout used a manager bypass, so its timestamp isn't when the work ended — this day's hours and overtime are counted up to the last delivered order at ${cc.time}.">🔓 counted ${cc.time}</div>`
            : `<div style="font-size:10px;font-weight:700;color:#B45309;margin-top:2px;white-space:nowrap;" title="Checkout used a manager bypass and no orders were delivered this day — nothing past the shift is counted.">🔓 nothing counted</div>`)
        : '';
      const hours = day.hours_worked ? day.hours_worked.toFixed(1) + 'h' : '-';
      // ⭐ 5-Oct-2026: the figure that COUNTS (payroll's), with the waive spelled out under it.
      const lateBy = day.late_minutes > 0 ? day.late_minutes + ' min' : (lateRaw > 0 ? '0 min' : '-');
      const lateSub = lateWaived > 0
        ? `<div style="font-size:10px;font-weight:600;color:#047857;margin-top:2px;white-space:nowrap;" title="${lateRaw} min late, ${lateWaived} min waived by a manager — ${day.late_minutes || 0} min count.">✔ ${lateWaived} of ${lateRaw} waived</div>`
        : '';
      const overtime = day.overtime_minutes > 0 ? day.overtime_minutes + ' min' : '-';

      const ordersDelivered = day.total_orders_delivered || 0;
      const firstDelivery = day.first_delivery_time || '-';
      const lastDelivery = day.last_delivery_time || '-';

      // Meter values
      const meterStart = day.meter_start || null;
      const meterEnd = day.meter_end || null;
      /**
       * 🏍️🚚 TWO ODOMETERS, ONE ROW (Rajab 11-Sep-2026). This modal subtracted the raw readings
       * itself and printed "67874 km" — the SIXTH place doing its own arithmetic. The server's
       * `meter_distance` (NULL on a split day, via MeterPairHelper) and `meter_machines` are the
       * one answer; the local subtraction survives only for an older server that sends neither.
       */
      const mm = day.meter_machines || null;
      const isSplit = !!(mm && mm.split);
      const esc = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      const meterDistance = isSplit ? null
        : (day.meter_distance !== undefined && day.meter_distance !== null) ? Number(day.meter_distance)
        : ((meterStart && meterEnd) ? Math.abs(parseInt(meterEnd) - parseInt(meterStart)) : null);

      // Previous day's meter_end (precomputed on the FULL list, filter-safe)
      let prevMeterEnd = day.__prevMeterEnd;
      let meterGap = null;
      // ⚠ Only when both ends are known to be the SAME machine (or unstamped, as before).
      const startVid = mm && mm.start_id ? mm.start_id : null;
      const gapComparable = !(startVid && day.__prevEndVid && Number(startVid) !== Number(day.__prevEndVid));
      if (prevMeterEnd && meterStart && gapComparable) {
        meterGap = parseInt(meterStart) - parseInt(prevMeterEnd);
      }

      // Build meter display with values and gap indicator
      let meterValuesHtml = '-';
      if (meterStart || meterEnd) {
        meterValuesHtml = `<div style="font-size: 11px; text-align: center;">`;
        if (isSplit) {
          meterValuesHtml += `<div style="color: #374151;">${meterStart} → ${meterEnd}</div>`;
          meterValuesHtml += `<div style="color:#B45309;font-weight:600;" title="Start ${meterStart} on ${esc(mm.start_label || 'one machine')} · close ${meterEnd} on ${esc(mm.end_label || 'another')} — two different odometers, so the difference is not a distance">🏍️🚚 two machines</div>`;
          meterValuesHtml += `<div style="font-size:9px;color:#6b7280;">${esc(mm.start_label || '')} → ${esc(mm.end_label || '')}</div>`;
        } else if (meterStart && meterEnd) {
          meterValuesHtml += `<div style="color: #374151;">${meterStart} → ${meterEnd}</div>`;
          meterValuesHtml += `<div style="color: #16a34a; font-weight: 600;">${meterDistance != null ? meterDistance + ' km' : '—'}</div>`;
        } else if (meterStart) {
          meterValuesHtml += `<div style="color: #374151;">${meterStart} →</div>`;
          meterValuesHtml += `<div style="color: #9ca3af;">No end</div>`;
        } else {
          meterValuesHtml += `<div style="color: #9ca3af;">→ ${meterEnd}</div>`;
        }
        // Show gap indicator if previous day's meter_end exists
        if (prevMeterEnd && meterStart) {
          if (meterGap === 0) {
            meterValuesHtml += `<div style="font-size: 9px; color: #16a34a; margin-top: 2px;">✓ matches prev</div>`;
          } else if (Math.abs(meterGap) <= 25) {
            // Normal gap up to 25km (personal use, minor discrepancies)
            meterValuesHtml += `<div style="font-size: 9px; color: #6b7280; margin-top: 2px;">${meterGap > 0 ? '+' : ''}${meterGap} km</div>`;
          } else {
            // Warning for gaps > 25km
            meterValuesHtml += `<div style="font-size: 9px; color: #dc2626; margin-top: 2px; font-weight: 600;">⚠️ ${meterGap > 0 ? '+' : ''}${meterGap} km gap</div>`;
          }
        }
        meterValuesHtml += `</div>`;
      }
      
      // Meter pictures
      const hasPictureStart = day.picture_start && day.picture_start !== '-';
      const hasPictureEnd = day.picture_end && day.picture_end !== '-';
      // Build with /storage/ by default and fallback to proxy in viewer
      const pictureStartPath = hasPictureStart ? day.picture_start : null;
      const pictureEndPath = hasPictureEnd ? day.picture_end : null;
      
      let meterPicsHtml = '-';
      if (hasPictureStart || hasPictureEnd) {
        meterPicsHtml = '<div style="display: flex; gap: 4px; justify-content: center;">';
        if (hasPictureStart) {
          meterPicsHtml += `<button onclick="viewMeterPicturePath('${pictureStartPath}')" style="background: #dbeafe; color: #1e40af; border: none; padding: 2px 6px; border-radius: 4px; cursor: pointer; font-size: 10px;">📷</button>`;
        }
        if (hasPictureEnd) {
          meterPicsHtml += `<button onclick="viewMeterPicturePath('${pictureEndPath}')" style="background: #fef3c7; color: #92400e; border: none; padding: 2px 6px; border-radius: 4px; cursor: pointer; font-size: 10px;">📷</button>`;
        }
        meterPicsHtml += '</div>';
      }
      
      return `
        <tr style="background: ${rowBg};">
          <td style="padding: 12px 16px; font-size: 13px; color: #111827; border-bottom: 1px solid #e5e7eb;">
            <div style="font-weight: 600;">${dayName}</div>
            <div style="font-size: 11px; color: #6b7280;">${formattedDate}</div>
          </td>
          <td style="padding: 12px 16px; font-size: 13px; text-align: center; border-bottom: 1px solid #e5e7eb;">
            <span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; background: ${statusBg}; color: ${statusColor};">
              ${status}
            </span>
          </td>
          <td style="padding: 12px 16px; font-size: 13px; color: #111827; border-bottom: 1px solid #e5e7eb;">${loginTime}</td>
          <td style="padding: 12px 16px; font-size: 13px; color: #111827; border-bottom: 1px solid #e5e7eb;">${logoutTime}${countedSub}</td>
          <td style="padding: 12px 16px; font-size: 13px; color: #111827; text-align: center; border-bottom: 1px solid #e5e7eb;">${hours}</td>
          <td style="padding: 12px 16px; font-size: 13px; color: ${day.late_minutes > 0 ? '#dc2626' : '#9ca3af'}; font-weight: ${day.late_minutes > 0 ? '600' : '400'}; text-align: center; border-bottom: 1px solid #e5e7eb;">${lateBy}${lateSub}</td>
          <td style="padding: 12px 16px; font-size: 13px; color: ${day.overtime_minutes > 0 ? '#16a34a' : '#9ca3af'}; font-weight: ${day.overtime_minutes > 0 ? '600' : '400'}; text-align: center; border-bottom: 1px solid #e5e7eb;">${overtime}</td>
          <td style="padding: 12px 16px; font-size: 13px; color: ${ordersDelivered > 0 ? '#2563eb' : '#9ca3af'}; font-weight: ${ordersDelivered > 0 ? '700' : '400'}; text-align: center; border-bottom: 1px solid #e5e7eb;">
            ${ordersDelivered > 0 ? '📦 ' + ordersDelivered : '-'}
          </td>
          <td style="padding: 12px 16px; font-size: 13px; color: ${firstDelivery !== '-' ? '#2563eb' : '#9ca3af'}; text-align: center; border-bottom: 1px solid #e5e7eb;">${firstDelivery}</td>
          <td style="padding: 12px 16px; font-size: 13px; color: ${lastDelivery !== '-' ? '#2563eb' : '#9ca3af'}; text-align: center; border-bottom: 1px solid #e5e7eb;">${lastDelivery}</td>
          <td style="padding: 8px 12px; font-size: 13px; text-align: center; border-bottom: 1px solid #e5e7eb;">${meterValuesHtml}</td>
          <td style="padding: 8px 12px; font-size: 13px; text-align: center; border-bottom: 1px solid #e5e7eb;">${meterPicsHtml}</td>
        </tr>
      `;
    }).join('');
}

function viewMeterPicture(imageUrl) {
  const modal = document.getElementById('meterPictureModal');
  const img = document.getElementById('meterPictureImage');
  if (modal && img) {
    img.src = imageUrl;
    modal.style.display = 'flex';
  }
}

// Prefer /storage/ and fall back to /public-storage/
function viewMeterPicturePath(relativePath) {
  const primary = `/storage/${relativePath}`;
  const fallback = `/public-storage/${relativePath}`;
  const modal = document.getElementById('meterPictureModal');
  const img = document.getElementById('meterPictureImage');
  if (modal && img) {
    img.onerror = function() {
      // Switch to fallback once if primary fails
      if (img.src !== window.location.origin + fallback) {
        img.src = fallback;
      }
    };
    img.src = primary;
    modal.style.display = 'flex';
  }
}

function closeMeterPictureModal() {
  const modal = document.getElementById('meterPictureModal');
  if (modal) {
    modal.style.display = 'none';
  }
}

function closeEmployeeDetails() {
  const modal = document.getElementById('employeeDetailsModal');
  if (modal) {
    modal.classList.add('hidden');
    modal.style.cssText = 'display: none !important;';
  }
}

// Make functions globally accessible
window.showEmployeeDetails = showEmployeeDetails;
window.closeEmployeeDetails = closeEmployeeDetails;

// ==================== Customize User List Functions ====================

async function openCustomizeUserList() {
  try {
    console.log('Opening customize user list modal...');
    const modal = document.getElementById('customizeUserListModal');
    modal.style.display = 'flex';
    
    // Load users with visibility status
    const res = await fetch('/attendance/users-visibility');
    const json = await res.json();
    
    if (json.success) {
      allUsersVisibility = json.data;
      salaryToggleAvailable = !!json.salary_toggle_available;
      visibilityChanges = {}; // Reset changes
      deliveryRiderChanges = {};
      salaryVisChanges = {};
      renderUsersVisibility();
      updateVisibleUsersCount();
    } else {
      alert('Error loading users: ' + (json.message || 'Unknown error'));
    }
  } catch(e) {
    console.error('Error opening customize user list:', e);
    alert('Failed to load user list');
  }
}

function closeCustomizeUserList() {
  const modal = document.getElementById('customizeUserListModal');
  modal.style.display = 'none';
  visibilityChanges = {}; // Reset unsaved changes
  deliveryRiderChanges = {};
  salaryVisChanges = {};
}

// Settings dropdown toggle
function toggleSettingsMenu() {
  const menu = document.getElementById('settingsMenu');
  menu.classList.toggle('hidden');
}

// Close settings menu when clicking outside
document.addEventListener('click', function(event) {
  const dropdown = document.getElementById('settingsDropdown');
  const menu = document.getElementById('settingsMenu');
  if (dropdown && menu && !dropdown.contains(event.target)) {
    menu.classList.add('hidden');
  }
});

function renderUsersVisibility() {
  const tbody = document.getElementById('usersVisibilityTableBody');
  const salHdr = document.getElementById('salaryVisHeader');
  if (salHdr) salHdr.style.display = salaryToggleAvailable ? '' : 'none';

  if (!allUsersVisibility || allUsersVisibility.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" style="padding: 20px; text-align: center; color: #6b7280;">No users found</td></tr>';
    return;
  }

  tbody.innerHTML = allUsersVisibility.map((user, index) => {
    const rowBg = index % 2 === 0 ? '#ffffff' : '#f9fafb';
    const curVis = visibilityChanges.hasOwnProperty(user.id) ? visibilityChanges[user.id] : !!user.is_visible;
    const curRider = deliveryRiderChanges.hasOwnProperty(user.id) ? deliveryRiderChanges[user.id] : !!Number(user.is_delivery_rider);
    const curSalary = salaryVisChanges.hasOwnProperty(user.id) ? salaryVisChanges[user.id] : !!Number(user.show_in_payroll);
    const salaryCell = salaryToggleAvailable ? `
        <td style="padding: 12px; text-align:center;">
          <input type="checkbox" data-user-id="${user.id}"
            ${curSalary ? 'checked' : ''} onchange="toggleSalaryVisibility(${user.id}, this.checked)">
        </td>` : '';

    return `
      <tr style="background: ${rowBg};" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='${rowBg}'">
        <td style="padding: 12px; font-weight: 500; color: #111827;">${user.fullname}</td>
        <td style="padding: 12px; color: #6b7280; font-size: 13px;">${user.role_name || 'N/A'}</td>
        <td style="padding: 12px; text-align:center;">
          <input type="checkbox" class="user-visibility-checkbox" data-user-id="${user.id}"
            ${curVis ? 'checked' : ''} onchange="toggleUserVisibility(${user.id}, this.checked)">
        </td>
        ${salaryCell}
        <td style="padding: 12px; text-align:center;">
          <input type="checkbox" data-user-id="${user.id}"
            ${curRider ? 'checked' : ''} onchange="toggleDeliveryRider(${user.id}, this.checked)">
        </td>
      </tr>
    `;
  }).join('');
}

function toggleDeliveryRider(userId, isRider) {
  deliveryRiderChanges[userId] = isRider;
}

function toggleSalaryVisibility(userId, show) {
  salaryVisChanges[userId] = show;
}

function toggleUserVisibility(userId, isVisible) {
  // Track the change
  visibilityChanges[userId] = isVisible;
  
  // Re-render to update the status badge
  renderUsersVisibility();
  updateVisibleUsersCount();
  updateSelectAllCheckbox();
}

function toggleSelectAllUsersVis(checkbox) {
  const isChecked = checkbox.checked;
  
  // Update all users' visibility
  allUsersVisibility.forEach(user => {
    visibilityChanges[user.id] = isChecked;
  });
  
  // Re-render
  renderUsersVisibility();
  updateVisibleUsersCount();
}

function updateSelectAllCheckbox() {
  const checkboxes = document.querySelectorAll('.user-visibility-checkbox');
  const selectAllCheckbox = document.getElementById('selectAllUsersVis');

  if (!selectAllCheckbox) return; // select-all removed (two-column layout)
  if (checkboxes.length === 0) return;
  
  const allChecked = Array.from(checkboxes).every(cb => cb.checked);
  const noneChecked = Array.from(checkboxes).every(cb => !cb.checked);
  
  selectAllCheckbox.checked = allChecked;
  selectAllCheckbox.indeterminate = !allChecked && !noneChecked;
}

function updateVisibleUsersCount() {
  const visibleCount = allUsersVisibility.filter(user => {
    const currentVisibility = visibilityChanges.hasOwnProperty(user.id) 
      ? visibilityChanges[user.id] 
      : user.is_visible;
    return currentVisibility;
  }).length;
  
  document.getElementById('visibleUsersCount').textContent = visibleCount;
}

async function saveUserVisibilityChanges() {
  const visIds = Object.keys(visibilityChanges);
  const riderIds = Object.keys(deliveryRiderChanges);
  const salaryIds = Object.keys(salaryVisChanges);
  if (visIds.length === 0 && riderIds.length === 0 && salaryIds.length === 0) {
    alert('No changes to save');
    closeCustomizeUserList();
    return;
  }

  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  try {
    const promises = [];
    // "Show in Attendance" changes
    visIds.forEach(userId => {
      promises.push(fetch('/attendance/update-visibility', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({
          user_id: parseInt(userId),
          is_visible: visibilityChanges[userId] ? 1 : 0,
          notes: visibilityChanges[userId] ? null : 'Hidden from attendance tracking'
        })
      }));
    });
    // "Delivery Rider" changes (rider-assign list)
    riderIds.forEach(userId => {
      promises.push(fetch('/attendance/update-delivery-rider', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ user_id: parseInt(userId), is_rider: deliveryRiderChanges[userId] ? 1 : 0 })
      }));
    });
    // "Show in Salary" changes (Payroll screen membership, independent of attendance)
    salaryIds.forEach(userId => {
      promises.push(fetch('/attendance/update-salary-visibility', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ user_id: parseInt(userId), show_in_payroll: salaryVisChanges[userId] ? 1 : 0 })
      }));
    });

    await Promise.all(promises);

    alert('✓ Saved. Attendance visibility, salary list and the delivery-rider list are updated.');
    closeCustomizeUserList();
    loadAttendanceForDate();
  } catch(e) {
    console.error('Error saving user list changes:', e);
    alert('Failed to save changes. Please try again.');
  }
}
window.toggleDeliveryRider = toggleDeliveryRider;

// Make customize user list functions globally accessible
window.openCustomizeUserList = openCustomizeUserList;
window.closeCustomizeUserList = closeCustomizeUserList;
window.toggleUserVisibility = toggleUserVisibility;
window.toggleSelectAllUsersVis = toggleSelectAllUsersVis;
window.saveUserVisibilityChanges = saveUserVisibilityChanges;

// ==================== GPS Audit Functions ====================

/**
 * ⭐ Show GPS Audit Modal for a user on a specific date
 */
async function showGpsAudit(userId, userName, date) {
  const modal = document.getElementById('gpsAuditModal');
  const content = document.getElementById('gpsAuditContent');
  const title = document.getElementById('gpsAuditTitle');
  const subtitle = document.getElementById('gpsAuditSubtitle');
  
  // Show modal with loading state
  modal.style.display = 'flex';
  title.textContent = `GPS Audit: ${userName}`;
  subtitle.textContent = `Loading data for ${date}...`;
  content.innerHTML = `
    <div style="text-align: center; padding: 40px; color: #6b7280;">
      <div style="font-size: 24px; margin-bottom: 8px;">⏳</div>
      Loading GPS data...
    </div>
  `;
  
  try {
    const res = await fetch(`/attendance/gps-audit?user_id=${userId}&date=${date}`);
    const json = await res.json();
    
    if (!json.success) {
      content.innerHTML = `
        <div style="text-align: center; padding: 40px; color: #dc2626;">
          <div style="font-size: 24px; margin-bottom: 8px;">❌</div>
          ${json.message || 'Failed to load GPS data'}
        </div>
      `;
      return;
    }
    
    // Update subtitle
    subtitle.textContent = `${date}`;
    
    // Render audit results
    if (!json.has_attendance) {
      content.innerHTML = `
        <div style="text-align: center; padding: 40px; color: #6b7280;">
          <div style="font-size: 48px; margin-bottom: 12px;">📅</div>
          <p style="font-size: 16px; margin: 0;">No attendance record for this date</p>
        </div>
      `;
      return;
    }
    
    // ── GPS v2 — ride-focused. One question: was GPS on while he was actually MOVING?
    //    Phases (ride-in / on-duty / ride-home) with moving-time coverage; parked gaps folded
    //    away. The old readings/expected/straight-line/histogram view is gone.
    const phases = json.phases || [];
    const dist = json.distance || {};
    const att = json.attendance;
    // Only ON-DUTY GPS is judged. Ride in / ride home is the rider's own commute — shown for
    // context, greyed out, and it can NEVER colour the verdict. (Server sets p.judged; older
    // payloads without the flag fall back to the phase name.)
    const isJudged = p => (p.judged !== undefined ? !!p.judged : p.name === 'On duty');
    const judged = phases.filter(isJudged);
    const worst = judged.length ? Math.min(...judged.map(p => p.coverage)) : null;
    const [vBg, vColor, vText] = worst === null
      ? ['#F3F4F6', '#6B7280', '– Not enough on-duty GPS to judge']
      : worst >= 80
      ? ['#E9F7EE', '#15803D', '✓ GPS stayed on while he was on duty']
      : worst >= 60
      ? ['#FDF3E3', '#B45309', '⚠ Some GPS gaps while on duty — check below']
      : ['#FDECEC', '#B91C1C', '❌ Poor GPS while on duty'];

    const phaseHtml = phases.map(p => {
      const jd = isJudged(p);
      const gapMin = p.moving_gaps.reduce((a, g) => a + g.min, 0);
      const gapPct = p.minutes > 0 ? Math.min(100, Math.round((gapMin / p.minutes) * 100)) : 0;
      const okPct = Math.max(0, 100 - gapPct);
      const col = !jd ? '#9CA3AF' : p.coverage >= 80 ? '#15803D' : p.coverage >= 60 ? '#B45309' : '#B91C1C';
      const movingTxt = p.moving_gaps.length ? p.moving_gaps.map(g => `${g.from}–${g.to} (${g.min}m)`).join(', ') : null;
      const bits = [];
      if (movingTxt) bits.push(`<span style="color:${jd ? '#B91C1C' : '#9CA3AF'};">GPS off while moving: ${movingTxt}</span>`);
      if (p.stationary_min) bits.push(`${p.stationary_min} min parked — ignored`);
      if (!jd) bits.push('commute — not counted');
      const note = bits.length
        ? `<div style="font-size:11px;color:#9CA3AF;margin-top:4px;">${bits.join(' · ')}</div>`
        : '';
      return `
        <div style="margin-bottom:14px;${jd ? '' : 'opacity:.65;'}">
          <div style="display:flex;justify-content:space-between;align-items:baseline;font-size:13px;margin-bottom:5px;">
            <div><strong style="font-size:13.5px;${jd ? '' : 'color:#6B7280;font-weight:600;'}">${p.name}</strong> <span style="color:#9CA3AF;font-size:11.5px;">${p.from} → ${p.to} · ${p.minutes}m</span></div>
            <strong style="color:${col};font-variant-numeric:tabular-nums;">${p.coverage}%</strong>
          </div>
          <div style="height:8px;border-radius:4px;background:#EFF0ED;overflow:hidden;display:flex;">
            <div style="height:100%;background:${jd ? '#22C55E' : '#D1D5DB'};width:${okPct}%;"></div>
            <div style="height:100%;background:${jd ? '#EF4444' : '#E5E7EB'};width:${gapPct}%;"></div>
          </div>${note}
        </div>`;
    }).join('');

    content.innerHTML = `
      <div style="padding:12px 16px;border-radius:10px;background:${vBg};color:${vColor};font-weight:700;margin-bottom:18px;">${vText}</div>
      ${phases.length ? phaseHtml : '<div style="padding:16px;background:#F9FAFB;border-radius:8px;color:#6B7280;text-align:center;">No ride phases to show for this day.</div>'}
      <div style="display:flex;gap:18px;align-items:baseline;border-top:1px solid #EFF0ED;margin-top:6px;padding-top:12px;font-size:13px;color:#6B7280;">
        <span>🕐 ${att.login_time}${att.logout_time ? ' → ' + att.logout_time : ' → ongoing'} · ${att.working_minutes} min</span>
        ${dist.gps_road_km != null ? `<span>🛣 Road distance <strong style="color:#111827;">${dist.gps_road_km} km</strong></span>` : ''}
      </div>
    `;

  } catch(e) {
    console.error('Error loading GPS audit:', e);
    content.innerHTML = `
      <div style="text-align: center; padding: 40px; color: #dc2626;">
        <div style="font-size: 24px; margin-bottom: 8px;">❌</div>
        Error loading GPS data: ${e.message}
      </div>
    `;
  }
}

function closeGpsAudit() {
  const modal = document.getElementById('gpsAuditModal');
  if (modal) {
    modal.style.display = 'none';
  }
}

// ── Meter details — the day's meter STORY (reuses the GPS modal shell + the same endpoint,
//    which now returns meter_story). last-night close → morning start (time/place/photo) →
//    tonight close → day km meter vs GPS. Answers "did he record at home, and does it add up".
async function showMeterDetail(userId, userName, date) {
  const modal = document.getElementById('gpsAuditModal');
  const content = document.getElementById('gpsAuditContent');
  const title = document.getElementById('gpsAuditTitle');
  const subtitle = document.getElementById('gpsAuditSubtitle');
  modal.style.display = 'flex';
  title.textContent = `Meter: ${userName}`;
  subtitle.textContent = `Loading ${date}…`;
  content.innerHTML = `<div style="text-align:center;padding:40px;color:#6b7280;">⏳ Loading meter data…</div>`;
  try {
    const res = await fetch(`/attendance/gps-audit?user_id=${userId}&date=${date}`);
    const json = await res.json();
    if (!json.success) { content.innerHTML = `<div style="text-align:center;padding:40px;color:#dc2626;">${json.message || 'Failed to load'}</div>`; return; }
    subtitle.textContent = `${date}`;
    if (!json.has_attendance || !json.meter_story) {
      content.innerHTML = `<div style="text-align:center;padding:40px;color:#6b7280;">No meter data for this date.</div>`;
      return;
    }
    const m = json.meter_story;
    // 'checkin' means "not GPS-confirmed at home" — NOT "at the office". Prefer the measured
    // place when the row has one; fall back to wording that claims only what we know.
    const srcLabel = (s, place) => s === 'home' ? '🏠 at home'
      : s === 'checkin' ? (place ? place.label : '⌂ not at home')
      : s === 'manager' ? '✎ by manager' : '';
    const srcColor = (s) => s === 'home' ? '#15803D' : s === 'checkin' ? '#B45309' : '#6B7280';
    const cam = (p) => p ? `<button type="button" onclick="viewMeterPicturePath('${String(p).replace(/'/g, "\\'")}')" title="View photo" style="background:none;border:none;cursor:pointer;font-size:13px;">📷</button>` : '<span title="no photo" style="filter:grayscale(1);opacity:.4;font-size:13px;">📷</span>';
    const row = (label, valHtml) => `<div style="display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #EFF0ED;align-items:baseline;"><span style="flex:none;width:104px;font-size:11px;font-weight:800;letter-spacing:.05em;color:#9CA3AF;text-transform:uppercase;">${label}</span><span style="font-size:13px;">${valHtml}</span></div>`;
    const num = (n) => n != null ? `<strong style="font-variant-numeric:tabular-nums;">${Number(n).toLocaleString()}</strong>` : '<span style="color:#9CA3AF;">—</span>';
    // overnight gap chip (start vs last night)
    let gapChip = '';
    // ⭐ Same verdict as the row chip: on a HANDOVER day the machine's last reading belongs to
    //    another rider, so "start − last night" has no meaning and must not accuse. The row chip
    //    already suppresses it — the popup two clicks away has to agree.
    if (m.prev && m.prev.transfer_day) {
      gapChip = `<span title="${m.prev.label ? m.prev.label + ' changed hands — ' : ''}the overnight check does not apply on a handover day" style="margin-left:8px;font-size:11px;font-weight:700;border-radius:5px;padding:1px 7px;color:#6B7280;background:#F3F4F6;border:1px solid #E5E7EB;">handover day · no overnight check</span>`;
    } else if (m.gap_km != null) {
      const big = Math.abs(m.gap_km) > 1;
      gapChip = `<span style="margin-left:8px;font-size:11px;font-weight:700;border-radius:5px;padding:1px 7px;${big ? 'color:#B91C1C;background:#FDECEC;border:1px solid #F5C6C6;' : 'color:#15803D;background:#E9F7EE;border:1px solid #BFE8CC;'}">overnight ${m.gap_km > 0 ? '+' : ''}${m.gap_km} km ${big ? '⚠' : '✓'}</span>`;
    }
    /**
     * 🏍️🚚 TWO MACHINES, TWO ODOMETERS (Rajab 11-Sep-2026).
     *
     * ⚠⚠ This modal used to print "DAY RIDDEN 67874 km · Δ 67804 ⚠" — his own bike's 7,610
     *    opened the day and the van's 75,484 closed it, so the subtraction was meaningless
     *    AND the red Δ accused him of a 67,804 km discrepancy. The server now sends
     *    `day_meter_km = null` on such a day (MeterPairHelper) plus `machines` (which end is
     *    which) and `legs` (what each machine actually did) — so the modal states the fact
     *    instead of computing a fiction.
     * ⚠ `machines`/`legs` are absent on an older server and on every ordinary day, so every
     *   other row of this modal renders exactly as before.
     */
    const mach = m.machines || null;
    const isSplit = !!(mach && mach.split);
    const esc = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const ofMachine = (label) => label
      ? ` <span style="color:#6B7280;font-size:12px;">— ${esc(label)}</span>` : '';

    // day km vs gps
    let dayChip = '';
    if (!isSplit && m.day_meter_km != null && m.day_road_km != null) {
      const diff = Math.abs(m.day_meter_km - m.day_road_km);
      const ok = diff <= Math.max(3, m.day_meter_km * 0.2);
      dayChip = `<span style="margin-left:8px;font-size:11px;font-weight:700;border-radius:5px;padding:1px 7px;${ok ? 'color:#15803D;background:#E9F7EE;border:1px solid #BFE8CC;' : 'color:#B91C1C;background:#FDECEC;border:1px solid #F5C6C6;'}">Δ ${Math.round(diff)} km ${ok ? '✓' : '⚠'}</span>`;
    }

    // What each machine actually did — the SAME legs the rider's own phone shows him.
    const legsHtml = (m.legs || [])
      .map(l => `<div style="font-size:12px;color:#374151;padding-top:3px;">`
        + `${l.vtype === 'van' ? '🚚' : '🏍️'} <strong>${esc(l.label)}</strong>`
        + (l.km != null ? ` · <strong>${Number(l.km).toLocaleString()} km</strong>` : ' · <span style="color:#9CA3AF;">no complete pair</span>')
        + (l.meter_start != null || l.meter_end != null
            ? ` <span style="color:#9CA3AF;">(${l.meter_start != null ? Number(l.meter_start).toLocaleString() : '—'} → ${l.meter_end != null ? Number(l.meter_end).toLocaleString() : '—'})</span>`
            : '')
        + `</div>`)
      .join('');

    const dayRow = isSplit
      ? row('Day ridden',
          `<span style="font-size:12px;font-weight:700;color:#B45309;background:#FFFBEB;border:1px solid #FDE68A;border-radius:5px;padding:1px 7px;">🏍️🚚 two machines</span>`
          + `<div style="color:#6B7280;font-size:12px;padding-top:5px;">The start and the close are of different odometers, so the difference between them is not a distance.</div>`
          + legsHtml
          + (m.day_road_km != null ? `<div style="font-size:12px;color:#374151;padding-top:3px;">🛰 <strong>${m.day_road_km} km</strong> <span style="color:#9CA3AF;">${m.day_road_is_gps ? 'gps' : 'gps roads'} — whole day, both machines</span></div>` : ''))
      : row('Day ridden', `${m.day_meter_km != null ? `<strong>${m.day_meter_km} km</strong> <span style="color:#9CA3AF;font-size:12px;">meter</span>` : '<span style="color:#9CA3AF;">no meter</span>'} ${m.day_road_km != null ? `· <strong>${m.day_road_km} km</strong> <span style="color:#9CA3AF;font-size:12px;">${m.day_road_is_gps ? 'gps' : 'gps roads'}</span>` : ''} ${dayChip}`);

    if (m.is_company_bike) {
      // Company bike — the full home story: last night, morning start (place/photo/overnight gap), end.
      content.innerHTML = `
        ${row('Last night', `${num(m.prev.value)} <span style="color:#6B7280;font-size:12px;">${m.prev.date ? '· ' + m.prev.date : ''}</span>`)}
        ${row('This morning', `${num(m.start.value)}${ofMachine(mach && mach.start_label)} <span style="color:#6B7280;font-size:12px;">${m.start.time ? '· ' + m.start.time : ''} ${m.start.source ? `· <span style="color:${srcColor(m.start.source)};font-weight:600;">${srcLabel(m.start.source, m.start.place)}</span>` : ''}${m.start.place && m.start.place.accuracy_m != null ? ` <span style="color:#9CA3AF;font-size:11px;">±${Math.round(m.start.place.accuracy_m)}m</span>` : ''}</span> ${cam(m.start.photo)} ${gapChip}`)}
        ${row('Day end', `${num(m.end.value)}${ofMachine(mach && mach.end_label)} <span style="color:#6B7280;font-size:12px;">${m.end.time ? '· ' + m.end.time : ''} ${m.end.source === 'home' ? '· <span style="color:#15803D;font-weight:600;">🏠 at home</span>' : ''}</span> ${cam(m.end.photo)}`)}
        ${dayRow}
      `;
    } else {
      // Non-bike — just start + end (no overnight / home framing; that's a bike-only concern).
      content.innerHTML = `
        ${row('Start', `${num(m.start.value)}${ofMachine(mach && mach.start_label)} ${m.start.time ? `<span style="color:#6B7280;font-size:12px;">· ${m.start.time}</span>` : ''} ${cam(m.start.photo)}`)}
        ${row('End', `${num(m.end.value)}${ofMachine(mach && mach.end_label)} ${m.end.time ? `<span style="color:#6B7280;font-size:12px;">· ${m.end.time}</span>` : ''} ${cam(m.end.photo)}`)}
        ${dayRow}
      `;
    }
  } catch (e) {
    content.innerHTML = `<div style="text-align:center;padding:40px;color:#dc2626;">Error: ${e.message}</div>`;
  }
}

// Make GPS audit functions globally accessible
window.showGpsAudit = showGpsAudit;
window.closeGpsAudit = closeGpsAudit;

// ========================================
// ⛽ Fuel Rate Groups Management
// ========================================
var fuelRateGroups = [];
var fuelAllRiders = [];

// ---- Attendance Rules (year cycle + meter thresholds) ----
async function openAttendanceRules() {
  var modal = document.getElementById('attRulesModal');
  modal.style.display = 'flex';
  updateRuleCycleHint();
  try {
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var resp = await fetch('/attendance/settings', { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken } });
    var data = await resp.json();
    if (data && data.success) {
      document.getElementById('ruleCycleStart').value = data.cycle_start || '';
      document.getElementById('ruleCycleEnd').value = data.cycle_end || '';
      document.getElementById('ruleGpsWarn').value = data.meter_gps_warn_km != null ? data.meter_gps_warn_km : 10;
      document.getElementById('ruleOvernight').value = data.overnight_grace_km != null ? data.overnight_grace_km : 30;
      document.getElementById('rulePetrolWindow').value = data.petrol_window_days != null ? data.petrol_window_days : 5;
      document.getElementById('rulePetrolWindowMgr').value = data.petrol_window_days_manager != null ? data.petrol_window_days_manager : 30;
      document.getElementById('ruleLeaveTotal').value = data.leave_quota_total != null ? data.leave_quota_total : 10;
      document.getElementById('ruleSamedayCap').value = data.leave_sameday_cap != null ? data.leave_sameday_cap : 4;
      document.getElementById('ruleSamedayCutoff').value = data.leave_sameday_cutoff || '10:00';
      document.getElementById('ruleTargetHours').value = data.shift_target_hours != null ? data.shift_target_hours : 9;
      document.getElementById('ruleCheckoutEnabled').checked = (Number(data.checkout_rule_enabled) === 1);
      document.getElementById('ruleCheckoutWindow').value = data.checkout_window_mins != null ? data.checkout_window_mins : 15;
      document.getElementById('ruleCheckoutRadius').value = data.checkout_radius_m != null ? data.checkout_radius_m : 150;
      document.getElementById('ruleCheckinRequireLocation').checked = (Number(data.require_location) === 1);
      document.getElementById('ruleOfficeAtRadius').value = data.office_at_radius_m != null ? data.office_at_radius_m : 300;
      updateRuleCycleHint();
    }
  } catch(e) {
    console.error('Failed to load attendance settings:', e);
  }
  // live hint as the user edits the dates
  document.getElementById('ruleCycleStart').oninput = updateRuleCycleHint;
  document.getElementById('ruleCycleEnd').oninput = updateRuleCycleHint;
}

// Accordion — one rule section open at a time.
function toggleRuleSection(id) {
  var ids = ['secCycle', 'secMeter', 'secLeave', 'secOvertime', 'secCheckin', 'secCheckout'];
  ids.forEach(function (b) {
    var body = document.getElementById(b);
    var chev = document.getElementById(b + 'Chev');
    if (!body) return;
    var open = (b === id) && body.style.display === 'none';
    body.style.display = open ? 'block' : 'none';
    if (chev) chev.textContent = open ? '▾' : '▸';
  });
}

function closeAttendanceRules() {
  document.getElementById('attRulesModal').style.display = 'none';
}

function updateRuleCycleHint() {
  var s = document.getElementById('ruleCycleStart').value;
  var e = document.getElementById('ruleCycleEnd').value;
  var hint = document.getElementById('ruleCycleHint');
  if (!s || !e) { hint.textContent = ''; return; }
  var fmt = function(d) {
    var parts = d.split('-');
    if (parts.length !== 3) return d;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[parseInt(parts[1],10)-1] + ' ' + parseInt(parts[2],10) + ', ' + parts[0];
  };
  if (s > e) { hint.style.color = '#dc2626'; hint.textContent = '⚠ Cycle end must be on or after the start.'; return; }
  hint.style.color = '#2563eb';
  hint.textContent = 'Cycle: ' + fmt(s) + ' → ' + fmt(e);
}

async function saveAttendanceRules() {
  var s = document.getElementById('ruleCycleStart').value;
  var e = document.getElementById('ruleCycleEnd').value;
  if (s && e && s > e) { alert('Cycle end must be on or after the cycle start.'); return; }
  var btn = document.getElementById('ruleSaveBtn');
  var orig = btn.textContent;
  btn.disabled = true; btn.textContent = 'Saving…';
  try {
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var resp = await fetch('/attendance/settings', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
      body: JSON.stringify({
        cycle_start: s,
        cycle_end: e,
        meter_gps_warn_km: document.getElementById('ruleGpsWarn').value,
        overnight_grace_km: document.getElementById('ruleOvernight').value,
        petrol_window_days: document.getElementById('rulePetrolWindow').value,
        petrol_window_days_manager: document.getElementById('rulePetrolWindowMgr').value,
        leave_quota_total: document.getElementById('ruleLeaveTotal').value,
        leave_sameday_cap: document.getElementById('ruleSamedayCap').value,
        leave_sameday_cutoff: document.getElementById('ruleSamedayCutoff').value,
        shift_target_hours: document.getElementById('ruleTargetHours').value,
        checkout_rule_enabled: document.getElementById('ruleCheckoutEnabled').checked ? 1 : 0,
        checkout_window_mins: document.getElementById('ruleCheckoutWindow').value,
        checkout_radius_m: document.getElementById('ruleCheckoutRadius').value,
        require_location: document.getElementById('ruleCheckinRequireLocation').checked ? 1 : 0,
        office_at_radius_m: document.getElementById('ruleOfficeAtRadius').value
      })
    });
    var data = await resp.json();
    if (data && data.success) {
      closeAttendanceRules();
      if (typeof loadMonthTab === 'function') { try { loadMonthTab(); } catch(_){} }
      alert('Attendance rules saved.');
    } else {
      alert('Could not save: ' + (data.message || 'Unknown error'));
    }
  } catch(err) {
    console.error('saveAttendanceRules failed:', err);
    alert('Could not save the attendance rules.');
  }
  btn.disabled = false; btn.textContent = orig;
}

async function openFuelRateModal() {
  var modal = document.getElementById('fuelRateModal');
  modal.style.display = 'flex';
  document.getElementById('fuelRateLoading').style.display = 'block';
  document.getElementById('fuelRateContent').style.display = 'none';

  try {
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var resp = await fetch('/attendance/fuel-rate-groups', {
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
    });
    var data = await resp.json();
    if (data.success) {
      fuelRateGroups = data.rate_groups || [];
      fuelAllRiders = data.all_riders || [];
      renderFuelRateGroups();
    } else {
      alert('Failed to load fuel rate data: ' + (data.message || 'Unknown error'));
    }
  } catch(e) {
    console.error('Failed to load fuel rates:', e);
    alert('Failed to load fuel rate data');
  }

  document.getElementById('fuelRateLoading').style.display = 'none';
  document.getElementById('fuelRateContent').style.display = 'block';
}

function closeFuelRateModal() {
  document.getElementById('fuelRateModal').style.display = 'none';
}

function renderFuelRateGroups() {
  var container = document.getElementById('fuelRateGroupsList');
  if (fuelRateGroups.length === 0) {
    fuelRateGroups = [{id: null, name: 'Default', rate: 10, user_ids: '', users: [], _expanded: true}];
  }

  var html = '';
  fuelRateGroups.forEach(function(group, idx) {
    var userIds = group.user_ids ? group.user_ids.split(',').map(function(id){ return id.trim(); }).filter(Boolean) : [];
    var isExpanded = group._expanded;

    html += '<div style="border: 1px solid ' + (isExpanded ? '#F97316' : '#E5E7EB') + '; border-radius: 10px; margin-bottom: 12px; overflow: hidden; background: #fff;">';
    html += '<div onclick="toggleFuelGroup(' + idx + ')" style="cursor: pointer; display: flex; align-items: center; padding: 12px 16px; background: ' + (isExpanded ? '#FFF7ED' : '#F9FAFB') + ';">';
    html += '<div style="flex:1;"><strong style="font-size: 15px; color: #1F2937;">' + (group.name || 'Group ' + (idx+1)) + '</strong>';
    html += '<div style="font-size: 12px; color: #6B7280; margin-top: 2px;">Rs. ' + group.rate + '/km · ' + userIds.length + ' rider' + (userIds.length !== 1 ? 's' : '') + '</div></div>';
    html += '<span style="font-size: 18px; color: #9CA3AF;">' + (isExpanded ? '▲' : '▼') + '</span></div>';

    if (isExpanded) {
      html += '<div style="padding: 16px;">';
      html += '<div style="margin-bottom: 12px;"><label style="font-size: 12px; font-weight: 600; color: #374151; display: block; margin-bottom: 4px;">Group Name</label>';
      html += '<input type="text" id="fuelGroupName_' + idx + '" value="' + (group.name || '').replace(/"/g, '&quot;') + '" style="width: 100%; border: 1px solid #D1D5DB; border-radius: 8px; padding: 8px 12px; font-size: 14px;" placeholder="e.g. Bike Riders"></div>';
      html += '<div style="margin-bottom: 12px;"><label style="font-size: 12px; font-weight: 600; color: #374151; display: block; margin-bottom: 4px;">Rate (Rs/km)</label>';
      html += '<input type="number" step="0.01" min="0.01" id="fuelGroupRate_' + idx + '" value="' + group.rate + '" style="width: 100%; border: 1px solid #D1D5DB; border-radius: 8px; padding: 8px 12px; font-size: 14px;" placeholder="e.g. 12.5"></div>';

      html += '<div style="margin-bottom: 8px;"><label style="font-size: 12px; font-weight: 600; color: #374151; display: block; margin-bottom: 4px;">Assign Riders (' + userIds.length + ' selected)</label>';
      html += '<input type="text" id="fuelRiderSearch_' + idx + '" oninput="filterFuelRiders(' + idx + ')" style="width: 100%; border: 1px solid #D1D5DB; border-radius: 8px; padding: 6px 12px; font-size: 13px; margin-bottom: 8px;" placeholder="Search riders...">';
      html += '<div id="fuelRiderList_' + idx + '" style="max-height: 200px; overflow-y: auto; border: 1px solid #E5E7EB; border-radius: 8px; padding: 4px;">';

      fuelAllRiders.forEach(function(rider) {
        var riderId = String(rider.id);
        var isChecked = userIds.includes(riderId);
        var otherGroup = getFuelGroupForRider(riderId, idx);

        html += '<label style="display: flex; align-items: center; padding: 6px 8px; cursor: pointer; border-bottom: 1px solid #F3F4F6; gap: 8px;">';
        html += '<input type="checkbox" data-group="' + idx + '" data-rider="' + riderId + '" ' + (isChecked ? 'checked' : '') + ' onchange="handleFuelRiderToggle(' + idx + ',' + riderId + ', this.checked)" style="accent-color: #F97316; width: 16px; height: 16px;">';
        html += '<span style="flex: 1; font-size: 13px; color: ' + (otherGroup !== null ? '#9CA3AF' : '#1F2937') + ';" class="fuel-rider-name">' + rider.name + '</span>';
        if (otherGroup !== null) {
          html += '<span style="font-size: 10px; color: #F97316; font-weight: 600;">in ' + (fuelRateGroups[otherGroup]?.name || 'Group ' + (otherGroup + 1)) + '</span>';
        }
        html += '</label>';
      });

      html += '</div></div>';

      if (fuelRateGroups.length > 1) {
        html += '<button onclick="removeFuelGroup(' + idx + ')" style="margin-top: 8px; padding: 6px 14px; border-radius: 6px; background: #FEE2E2; color: #DC2626; border: none; font-size: 12px; font-weight: 600; cursor: pointer;">Remove Group</button>';
      }
      html += '</div>';
    }
    html += '</div>';
  });

  container.innerHTML = html;
}

function toggleFuelGroup(idx) {
  syncFuelGroupInputs();
  fuelRateGroups[idx]._expanded = !fuelRateGroups[idx]._expanded;
  renderFuelRateGroups();
}

function syncFuelGroupInputs() {
  fuelRateGroups.forEach(function(group, idx) {
    var nameEl = document.getElementById('fuelGroupName_' + idx);
    var rateEl = document.getElementById('fuelGroupRate_' + idx);
    if (nameEl) group.name = nameEl.value;
    if (rateEl) group.rate = parseFloat(rateEl.value) || group.rate;
  });
}

function addFuelGroup() {
  syncFuelGroupInputs();
  fuelRateGroups.forEach(function(g) { g._expanded = false; });
  fuelRateGroups.push({id: null, name: '', rate: 0, user_ids: '', users: [], _expanded: true});
  renderFuelRateGroups();
}

function removeFuelGroup(idx) {
  if (fuelRateGroups.length <= 1) { alert('At least one rate group is required.'); return; }
  if (!confirm('Remove "' + (fuelRateGroups[idx].name || 'Unnamed') + '" group?')) return;
  syncFuelGroupInputs();
  fuelRateGroups.splice(idx, 1);
  renderFuelRateGroups();
}

function getFuelGroupForRider(riderId, excludeIdx) {
  for (var i = 0; i < fuelRateGroups.length; i++) {
    if (i === excludeIdx) continue;
    var ids = fuelRateGroups[i].user_ids ? fuelRateGroups[i].user_ids.split(',').map(function(id){ return id.trim(); }) : [];
    if (ids.includes(String(riderId))) return i;
  }
  return null;
}

function handleFuelRiderToggle(groupIdx, riderId, isChecked) {
  syncFuelGroupInputs();
  var rid = String(riderId);

  if (isChecked) {
    // Remove from any other group
    fuelRateGroups.forEach(function(g, i) {
      if (i !== groupIdx && g.user_ids) {
        var ids = g.user_ids.split(',').map(function(id){ return id.trim(); }).filter(Boolean);
        ids = ids.filter(function(id) { return id !== rid; });
        g.user_ids = ids.join(',');
      }
    });
    // Add to this group
    var currentIds = fuelRateGroups[groupIdx].user_ids ? fuelRateGroups[groupIdx].user_ids.split(',').map(function(id){ return id.trim(); }).filter(Boolean) : [];
    if (!currentIds.includes(rid)) currentIds.push(rid);
    fuelRateGroups[groupIdx].user_ids = currentIds.join(',');
  } else {
    // Remove from this group
    var ids = fuelRateGroups[groupIdx].user_ids ? fuelRateGroups[groupIdx].user_ids.split(',').map(function(id){ return id.trim(); }).filter(Boolean) : [];
    ids = ids.filter(function(id) { return id !== rid; });
    fuelRateGroups[groupIdx].user_ids = ids.join(',');
  }

  renderFuelRateGroups();
}

function filterFuelRiders(groupIdx) {
  var searchEl = document.getElementById('fuelRiderSearch_' + groupIdx);
  if (!searchEl) return;
  var query = searchEl.value.toLowerCase();
  var listEl = document.getElementById('fuelRiderList_' + groupIdx);
  if (!listEl) return;
  var labels = listEl.querySelectorAll('label');
  labels.forEach(function(label) {
    var name = label.querySelector('.fuel-rider-name');
    if (name) {
      label.style.display = name.textContent.toLowerCase().includes(query) ? 'flex' : 'none';
    }
  });
}

async function saveFuelRateGroups() {
  syncFuelGroupInputs();

  // Validate
  for (var i = 0; i < fuelRateGroups.length; i++) {
    var g = fuelRateGroups[i];
    if (!g.name || !g.name.trim()) { alert('Group ' + (i+1) + ' needs a name.'); return; }
    if (!g.rate || g.rate <= 0) { alert('Group "' + g.name + '" needs a valid rate greater than 0.'); return; }
  }

  // Check duplicates
  var seen = {};
  for (var i = 0; i < fuelRateGroups.length; i++) {
    var ids = fuelRateGroups[i].user_ids ? fuelRateGroups[i].user_ids.split(',').map(function(id){ return id.trim(); }).filter(Boolean) : [];
    for (var j = 0; j < ids.length; j++) {
      if (seen[ids[j]]) {
        var rider = fuelAllRiders.find(function(r){ return String(r.id) === ids[j]; });
        alert((rider ? rider.name : 'A user') + ' is in multiple groups. Each user can only be in one group.');
        return;
      }
      seen[ids[j]] = true;
    }
  }

  var saveBtn = document.getElementById('fuelRateSaveBtn');
  saveBtn.disabled = true;
  saveBtn.textContent = 'Saving...';

  try {
    var groups = fuelRateGroups.map(function(g) {
      return { id: g.id || null, name: g.name.trim(), rate: parseFloat(g.rate), user_ids: g.user_ids || '' };
    });

    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var resp = await fetch('/attendance/fuel-rate-groups', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
      body: JSON.stringify({ groups: groups })
    });

    var data = await resp.json();
    if (data.success) {
      alert('Fuel rate groups saved successfully!');
      closeFuelRateModal();
    } else {
      alert('Error: ' + (data.message || 'Failed to save'));
    }
  } catch(e) {
    console.error('Save error:', e);
    alert('Failed to save fuel rate groups');
  }

  saveBtn.disabled = false;
  saveBtn.textContent = 'Save All Groups';
}

</script>

<!-- ⛽ Fuel Rate Groups Modal -->
<div id="fuelRateModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; padding: 16px;">
  <div style="background: #fff; border-radius: 16px; max-width: 600px; width: 100%; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px rgba(0,0,0,0.25);" onclick="event.stopPropagation()">
    <div style="padding: 20px 24px 12px; border-bottom: 1px solid #E5E7EB; display: flex; justify-content: space-between; align-items: center;">
      <div>
        <h2 style="font-size: 18px; font-weight: 700; color: #1F2937; margin: 0;">⛽ Fuel Rate Groups</h2>
        <p style="font-size: 13px; color: #6B7280; margin: 4px 0 0;">Each group has its own rate. Assign riders — each rider can only be in one group.</p>
      </div>
      <button onclick="closeFuelRateModal()" style="background: none; border: none; font-size: 24px; color: #9CA3AF; cursor: pointer; padding: 4px;">&times;</button>
    </div>
    <div id="fuelRateLoading" style="padding: 40px; text-align: center; color: #6B7280;">Loading...</div>
    <div id="fuelRateContent" style="display: none; flex: 1; overflow-y: auto; padding: 16px 24px;">
      <div id="fuelRateGroupsList"></div>
    </div>
    <div style="padding: 12px 24px 20px; border-top: 1px solid #E5E7EB; display: flex; justify-content: space-between; align-items: center;">
      <button onclick="addFuelGroup()" style="padding: 8px 16px; border-radius: 8px; background: #FFF7ED; color: #F97316; border: 1px solid #FDBA74; font-weight: 600; font-size: 13px; cursor: pointer;">+ Add Group</button>
      <div style="display: flex; gap: 8px;">
        <button onclick="closeFuelRateModal()" style="padding: 8px 20px; border-radius: 8px; background: #fff; color: #6B7280; border: 1px solid #D1D5DB; font-weight: 600; font-size: 14px; cursor: pointer;">Cancel</button>
        <button id="fuelRateSaveBtn" onclick="saveFuelRateGroups()" style="padding: 8px 24px; border-radius: 8px; background: #F97316; color: #fff; border: none; font-weight: 700; font-size: 14px; cursor: pointer;">Save All Groups</button>
      </div>
    </div>
  </div>
</div>

@include('partials.home-meter-alerts')
@include('partials.checkout-stuck-alerts')
{{-- 🛢 A MACHINE needing service — separate audience and key from the meter
     banner above; see partials/service-alerts.blade.php. --}}
@include('partials.service-alerts')
@include('partials.vehicle-ticket-alerts')
@include('partials.workshop-alerts')
@include('partials.shift-approval-alerts')

@endsection
