@extends('layouts.app')

@section('title', 'Attendance Reports')

@section('content')
<div class="max-w-7xl mx-auto p-6">
  <!-- Header -->
  <div class="flex justify-between items-center mb-6">
    <div>
      <h1 class="text-2xl font-semibold text-gray-900">Attendance Reports</h1>
      <p class="text-sm text-gray-500 mt-1">Comprehensive analytics and insights</p>
    </div>
    <div class="flex items-center gap-2">
      <a 
        href="/shifts"
        class="px-4 py-2 bg-white text-gray-800 rounded-lg hover:bg-gray-100 transition font-semibold shadow-md border-2 border-gray-300 inline-block text-center"
        style="text-decoration: none;"
      >
        📅 Shift Management
      </a>
      <a 
        href="/holidays"
        class="px-4 py-2 bg-white text-gray-800 rounded-lg hover:bg-gray-100 transition font-semibold shadow-md border-2 border-gray-300 inline-block text-center"
        style="text-decoration: none;"
      >
        🎉 Public Holidays
      </a>
      <a href="/attendance" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
        ← Back to Attendance
      </a>
    </div>
  </div>

  <!-- Month Selector & Working Days Config -->
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 mb-6">
    <div class="flex items-center gap-4 mb-4">
      <label class="text-sm font-semibold text-gray-700">Select Month:</label>
      <input 
        type="month" 
        id="reportMonth" 
        value="<?php echo date('Y-m'); ?>"
        class="px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
        onchange="loadMonthlyReport()"
      >
      <button 
        onclick="toggleWorkingDaysConfig()" 
        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium"
      >
        ⚙️ Configure Working Days
      </button>
      <button 
        onclick="exportToCSV()" 
        class="ml-auto px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-medium"
      >
        📥 Export CSV
      </button>
    </div>

    <!-- Working Days Configuration (Collapsible) -->
    <div id="workingDaysConfig" class="hidden border-t border-gray-200 pt-4 mt-4" style="display: none;">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Working Days Selection -->
        <div>
          <h4 class="text-sm font-semibold text-gray-700 mb-3">Select Working Days:</h4>
          <div class="space-y-2">
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_monday" value="1" checked onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Monday</span>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_tuesday" value="2" checked onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Tuesday</span>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_wednesday" value="3" checked onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Wednesday</span>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_thursday" value="4" checked onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Thursday</span>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_friday" value="5" checked onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Friday</span>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_saturday" value="6" checked onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Saturday</span>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_sunday" value="0" onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Sunday</span>
            </label>
          </div>
          
          <!-- Working Days Counter + Apply Button RIGHT HERE -->
          <div class="mt-4 p-4 bg-blue-50 border-2 border-blue-400 rounded-lg">
            <div class="flex items-center justify-between gap-3">
              <span class="text-base font-bold text-gray-800">
                Working Days: <span id="workingDaysCount" class="text-blue-600 text-2xl font-black">0</span>
              </span>
              <button 
                onclick="applyWorkingDaysConfig()" 
                class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition font-medium whitespace-nowrap"
              >
                ✓ Apply
              </button>
            </div>
          </div>
        </div>

        <!-- Holidays -->
        <div>
          <h4 class="text-sm font-semibold text-gray-700 mb-3">Exclude Holidays:</h4>
          <div class="space-y-2">
            <div class="flex gap-2">
              <input 
                type="date" 
                id="holidayDate" 
                class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
              >
              <button 
                onclick="addHoliday()" 
                class="px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium"
              >
                + Add
              </button>
            </div>
            <div id="holidaysList" class="mt-3 space-y-1">
              <!-- Holidays will be added here -->
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Summary Statistics - Compact One Row -->
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-4 mb-6">
    <div class="grid grid-cols-4 gap-4">
      <div class="text-center">
        <div class="flex justify-center mb-1">
          <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white">
            <span class="text-xl">👥</span>
          </div>
        </div>
        <p class="text-2xl font-bold text-blue-600" id="statTotalEmployees">0</p>
        <p class="text-xs text-gray-500 mt-1">Employees</p>
      </div>

      <div class="text-center">
        <div class="flex justify-center mb-1">
          <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-full flex items-center justify-center text-white">
            <span class="text-xl">✓</span>
          </div>
        </div>
        <p class="text-2xl font-bold text-green-600" id="statAvgAttendance">0%</p>
        <p class="text-xs text-gray-500 mt-1">Avg Attendance</p>
      </div>

      <div class="text-center">
        <div class="flex justify-center mb-1">
          <div class="w-12 h-12 bg-gradient-to-br from-red-500 to-red-600 rounded-full flex items-center justify-center text-white">
            <span class="text-xl">⚠</span>
          </div>
        </div>
        <p class="text-2xl font-bold text-red-600" id="statTotalLate">0</p>
        <p class="text-xs text-gray-500 mt-1">Late Instances</p>
      </div>

      <div class="text-center">
        <div class="flex justify-center mb-1">
          <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-purple-600 rounded-full flex items-center justify-center text-white">
            <span class="text-xl">⏱</span>
          </div>
        </div>
        <p class="text-2xl font-bold text-purple-600" id="statTotalHours">0h</p>
        <p class="text-xs text-gray-500 mt-1">Total Hours</p>
      </div>
    </div>
  </div>

  <!-- Employee Summary Table -->
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm mb-6">
    <div class="p-4 border-b border-gray-200 bg-gray-50">
      <h3 class="text-lg font-semibold text-gray-900">Employee Summary</h3>
      <p class="text-sm text-gray-500">Click on any employee to view daily details</p>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Employee</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Present Days</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Attendance %</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Absent</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">On Leave</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Late Days</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Late (mins)</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-purple-500 uppercase">Leaves (Year)</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody id="reportBody" class="bg-white divide-y divide-gray-200">
          <tr>
            <td colspan="9" class="px-4 py-8 text-center text-gray-500 text-sm">Loading report...</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Daily Details Modal - Simplified & Guaranteed Scrollable -->
<div id="dailyDetailsModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px;">
  <div id="dailyDetailsCard" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 1000px; height: 85vh; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden;" onclick="event.stopPropagation();">
      
      <!-- Header -->
      <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
          <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 50%; background: #dbeafe; display: flex; align-items: center; justify-content: center; color: #2563eb; font-size: 18px; font-weight: bold;">
              👤
            </div>
            <div>
              <h3 style="font-size: 18px; font-weight: 600; color: #111827; margin: 0;" id="modalEmployeeName">Employee Name</h3>
              <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;" id="modalMonthYear">Month Year</p>
            </div>
          </div>
          <button type="button" onclick="closeDailyDetails()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
      </div>

      <!-- Stats Bar - Matches Salary Section -->
      <div style="padding: 12px 24px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; flex-shrink: 0;">
        <div style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px;">
          <div style="text-align: center;">
            <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Present</p>
            <p style="font-size: 18px; font-weight: bold; color: #111827; margin: 0;" id="modalStatPresent">0</p>
          </div>
          <div style="text-align: center;">
            <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Absent</p>
            <p style="font-size: 18px; font-weight: bold; color: #dc2626; margin: 0;" id="modalStatAbsent">0</p>
          </div>
          <div style="text-align: center;">
            <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">On Leave</p>
            <p style="font-size: 18px; font-weight: bold; color: #3b82f6; margin: 0;" id="modalStatLeave">0</p>
          </div>
          <div style="text-align: center;">
            <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Late</p>
            <p style="font-size: 18px; font-weight: bold; color: #f59e0b; margin: 0;" id="modalStatLate">0</p>
            <p style="font-size: 9px; color: #f59e0b; margin: 4px 0 0 0;" id="modalStatLateHours">0h 0m</p>
          </div>
          <div style="text-align: center;">
            <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Overtime</p>
            <p style="font-size: 18px; font-weight: bold; color: #16a34a; margin: 0;" id="modalStatOT">0</p>
            <p style="font-size: 9px; color: #16a34a; margin: 4px 0 0 0;" id="modalStatOTHours">0h 0m</p>
          </div>
          <div style="text-align: center;">
            <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Total Hours</p>
            <p style="font-size: 18px; font-weight: bold; color: #7c3aed; margin: 0;" id="modalStatHours">0h</p>
          </div>
        </div>
      </div>

      <!-- Scrollable Table Container - THIS IS THE KEY PART -->
      <div style="flex: 1 1 auto; overflow-y: auto; overflow-x: hidden; min-height: 0; background: white;">
        <table style="width: 100%; border-collapse: collapse;">
          <thead style="position: sticky; top: 0; background: #f3f4f6; z-index: 10;">
            <tr>
              <th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb;">Date</th>
              <th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb;">Login</th>
              <th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb;">Logout</th>
              <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb;">Hours</th>
              <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb;">Late By</th>
              <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb;">Overtime</th>
              <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb;">Status</th>
              <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #16a34a; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb;">Meter</th>
              <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb;">Pics</th>
            </tr>
          </thead>
          <tbody id="dailyDetailsBody" style="background: white;">
            <!-- Populated by JS -->
          </tbody>
        </table>
      </div>

      <!-- Footer -->
      <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: white; flex-shrink: 0; display: flex; justify-content: flex-end;">
        <button 
          type="button"
          onclick="closeDailyDetails()" 
          style="padding: 10px 24px; background: #2563eb; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);"
        >
          Close
        </button>
      </div>

    </div>
</div>

<!-- Meter Picture Viewer Modal -->
<div id="meterPictureModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 100000; background-color: rgba(0, 0, 0, 0.9); align-items: center; justify-content: center; padding: 1rem;" onclick="if(event.target === this) closeMeterPictureModal();">
  <div style="position: relative; max-width: 90%; max-height: 90%; display: flex; flex-direction: column; align-items: center;" onclick="event.stopPropagation();">
    <button onclick="closeMeterPictureModal()" style="position: absolute; top: -40px; right: 0; background: rgba(255, 255, 255, 0.2); color: white; border: none; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 16px; font-weight: 600;">✕ Close</button>
    <img id="meterPictureImage" src="" style="max-width: 100%; max-height: 90vh; border-radius: 8px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);" />
  </div>
</div>

<script>
let reportData = [];
let currentMonth = '';
let workingDays = [1, 2, 3, 4, 5, 6]; // Mon-Sat by default (0=Sun, 1=Mon, ... 6=Sat)
let holidays = []; // Array of date strings 'YYYY-MM-DD'
let calculatedWorkingDays = 0;

document.addEventListener('DOMContentLoaded', function() {
  loadSavedConfiguration();
  loadMonthlyReport();
  calculateWorkingDays();
  
  // Auto-open detail modal if requested via URL parameter
  const urlParams = new URLSearchParams(window.location.search);
  const autoOpen = urlParams.get('auto_open');
  const userId = urlParams.get('user_id');
  const month = urlParams.get('month');
  
  if (autoOpen === '1' && userId) {
    // Set the month if specified
    if (month) {
      document.getElementById('reportMonth').value = month;
      currentMonth = month;
    }
    
    // Wait for data to load, then open the modal
    setTimeout(() => {
      showDailyDetails(userId);
    }, 1000); // Give time for the report to load
  }
});

// Load saved working days configuration from localStorage
function loadSavedConfiguration() {
  const saved = localStorage.getItem('attendanceWorkingDaysConfig');
  if (saved) {
    try {
      const config = JSON.parse(saved);
      workingDays = config.workingDays || [1, 2, 3, 4, 5, 6];
      holidays = config.holidays || [];
      
      // Update checkboxes to match saved config
      ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].forEach((day, index) => {
        const checkbox = document.getElementById(`day_${day}`);
        if (checkbox) {
          const jsDay = index === 6 ? 0 : index + 1;
          checkbox.checked = workingDays.includes(jsDay);
        }
      });
      
      // Render saved holidays
      renderHolidays();
      
      console.log('Loaded saved configuration:', config);
    } catch (e) {
      console.error('Error loading saved configuration:', e);
    }
  }
}

async function loadMonthlyReport() {
  const month = document.getElementById('reportMonth').value;
  currentMonth = month;

  try {
    const res = await fetch(`/attendance/monthly-report?month=${month}`);
    const json = await res.json();

    if (json.success) {
      reportData = json.data;
      renderReportTable(reportData);
      updateStatistics(reportData, month);
    }
  } catch(e) {
    console.error('Error loading monthly report', e);
    document.getElementById('reportBody').innerHTML = '<tr><td colspan="9" class="px-4 py-8 text-center text-red-500 text-sm">Error loading report</td></tr>';
  }
}

function renderReportTable(data) {
  const body = document.getElementById('reportBody');

  if (!data || data.length === 0) {
    body.innerHTML = '<tr><td colspan="10" class="px-4 py-8 text-center text-gray-500 text-sm">No data for selected month</td></tr>';
    return;
  }

  body.innerHTML = data.map(emp => {
    // Use each employee's individual working days from their shift schedule
    const userWorkingDays = emp.working_days || calculatedWorkingDays || 27;
    const attendancePerc = userWorkingDays > 0 ? ((emp.present_days / userWorkingDays) * 100).toFixed(1) : 0;
    const totalLateMins = emp.total_late_minutes ? Math.round(emp.total_late_minutes) : 0;
    // Overtime = the TARGET-based figure that earns bonus leave (owner ruling Jul-28) — the
    // same one the Attendance page and payroll use. `total_overtime_minutes` is the old
    // shift-END number that feeds salary and reads ~0 for everyone; it must not appear here.
    const totalOTMins = emp.overtime_target_minutes ? Math.round(emp.overtime_target_minutes) : 0;
    const otBonusDays = Number(emp.overtime_bonus_leaves) || 0;
    
    return `
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 text-sm font-medium text-gray-900">
          <div>${emp.fullname || 'Unknown'}</div>
          <div class="text-xs text-gray-500 mt-1">${emp.shift_name || 'Default Shift'}</div>
        </td>
        <td class="px-4 py-3 text-sm text-center">${emp.present_days} / ${userWorkingDays}</td>
        <td class="px-4 py-3 text-sm text-center">
          <span class="px-2 py-1 rounded-full text-xs font-medium ${attendancePerc >= 90 ? 'bg-green-100 text-green-700' : attendancePerc >= 75 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'}">
            ${attendancePerc}%
          </span>
        </td>
        <td class="px-4 py-3 text-sm text-center ${emp.absent_days > 0 ? 'text-red-600 font-semibold' : 'text-gray-400'}">${emp.absent_days || 0}</td>
        <td class="px-4 py-3 text-sm text-center ${emp.leave_days > 0 ? 'text-blue-600 font-semibold' : 'text-gray-400'}">${emp.leave_days || 0}</td>
        <td class="px-4 py-3 text-sm text-center ${emp.late_days > 0 ? 'text-orange-600 font-semibold' : 'text-gray-400'}">${emp.late_days}</td>
        <td class="px-4 py-3 text-sm text-center ${totalLateMins > 0 ? 'text-orange-600 font-semibold' : 'text-gray-400'}">${totalLateMins > 0 ? totalLateMins + 'm' : '-'}${
          /* ⭐ Sep-2026 — the figure above is NET of minutes a manager forgave. The endpoint
             already returns the split; without this line the column silently disagrees with
             the day rows underneath it and with Payroll. */
          (Number(emp.late_waived_minutes) || 0) > 0
            ? `<span style="display:block;font-size:9.5px;font-weight:600;color:#6B7280;">${emp.late_raw_minutes}m · ${emp.late_waived_minutes}m waived</span>`
            : ''
        }</td>
        <td class="px-4 py-3 text-sm text-center ${(emp.leaves_taken_year || 0) > 0 ? 'text-purple-600 font-semibold' : 'text-gray-400'}">${(emp.leaves_taken_year || 0) > 0 ? emp.leaves_taken_year + 'd' : '-'}</td>
        <td class="px-4 py-3 text-sm text-center">
          <button 
            onclick="showDailyDetails(${emp.user_id})"
            class="px-3 py-1 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition text-xs font-medium"
          >
            View Details
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

function updateStatistics(data, month) {
  const totalEmployees = data.length;
  const totalPresent = data.reduce((sum, emp) => sum + (emp.present_days || 0), 0);
  
  // Calculate average attendance using each employee's individual working days
  const totalPossibleDays = data.reduce((sum, emp) => sum + (emp.working_days || calculatedWorkingDays || 27), 0);
  const avgAttendance = totalPossibleDays > 0 ? ((totalPresent / totalPossibleDays) * 100).toFixed(1) : 0;
  
  const totalLate = data.reduce((sum, emp) => sum + (emp.late_days || 0), 0);
  const totalHours = data.reduce((sum, emp) => sum + (emp.total_hours || 0), 0);

  document.getElementById('statTotalEmployees').textContent = totalEmployees;
  document.getElementById('statAvgAttendance').textContent = avgAttendance + '%';
  document.getElementById('statTotalLate').textContent = totalLate;
  document.getElementById('statTotalHours').textContent = totalHours.toFixed(0) + 'h';
}

function showDailyDetails(userId) {
  console.log('showDailyDetails called with userId:', userId);
  console.log('reportData:', reportData);
  
  const employee = reportData.find(emp => emp.user_id == userId);
  if (!employee) {
    console.error('Employee not found:', userId);
    console.error('Available user_ids:', reportData.map(e => e.user_id));
    alert('Employee data not found. Check console for details.');
    return;
  }

  console.log('Found employee:', employee);
  console.log('Employee daily data:', employee.daily);

  // Ensure modal elements exist
  const modalName = document.getElementById('modalEmployeeName');
  const modalMonth = document.getElementById('modalMonthYear');
  const modalPresent = document.getElementById('modalStatPresent');
  const modalLate = document.getElementById('modalStatLate');
  const modalOT = document.getElementById('modalStatOT');
  const modalHours = document.getElementById('modalStatHours');
  const body = document.getElementById('dailyDetailsBody');
  
  if (!modalName || !body) {
    console.error('Modal elements not found!');
    alert('Error: Modal elements missing');
    return;
  }

  modalName.textContent = employee.fullname || 'Unknown';
  modalMonth.textContent = new Date(currentMonth + '-01').toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
  
  // Update stats to match salary section
  modalPresent.textContent = employee.present_days || 0;
  document.getElementById('modalStatAbsent').textContent = employee.absent_days || 0;
  document.getElementById('modalStatLeave').textContent = employee.leave_days || 0;
  modalLate.textContent = employee.late_days || 0;
  // Overtime tile = the bonus days those hours have earned, with the hours underneath.
  // Was a day-count off the shift-END overtime, which reads ~0 for everyone.
  (function () {
    const b = Number(employee.overtime_bonus_leaves) || 0;
    modalOT.textContent = b > 0 ? `${b} bonus day${b === 1 ? '' : 's'}` : '0';
  })();
  modalHours.textContent = (employee.total_hours || 0).toFixed(1) + 'h';
  
  // Add hours/minutes for late and overtime
  // API returns 'total_late_minutes' and 'total_overtime_minutes'
  const lateMinutes = Math.round(employee.total_late_minutes || 0);
  const lateHours = Math.floor(lateMinutes / 60);
  const lateMins = lateMinutes % 60;
  document.getElementById('modalStatLateHours').textContent = `${lateHours}h ${lateMins}m`;
  // Oct-2026 — the header figure is what COUNTS; say what was forgiven to get there.
  const lateWaivedHdr = Math.round(Number(employee.late_waived_minutes) || 0);
  if (lateWaivedHdr > 0) {
    document.getElementById('modalStatLateHours').textContent += ` · ${lateWaivedHdr}m waived`;
  }
  
  // TARGET-based minutes (the ones that earn the bonus days above) — not the shift-END
  // figure that feeds salary and shows ~0 because the resolved shift has no end time.
  const otMinutes = Math.round(employee.overtime_target_minutes || 0);
  const otHours = Math.floor(otMinutes / 60);
  const otMins = otMinutes % 60;
  document.getElementById('modalStatOTHours').textContent = `${otHours}h ${otMins}m`;

  if (!employee.daily || employee.daily.length === 0) {
    body.innerHTML = '<tr><td colspan="9" class="px-4 py-8 text-center text-gray-500">No daily records found for this month</td></tr>';
    console.warn('No daily data for employee');
  } else {
    // DEDUPLICATE: Remove duplicate dates (keep first occurrence)
    const uniqueDaily = [];
    const seenDates = new Set();
    
    for (const day of employee.daily) {
      if (!seenDates.has(day.attendance_date)) {
        seenDates.add(day.attendance_date);
        uniqueDaily.push(day);
      } else {
        console.warn('Duplicate removed:', day.attendance_date);
      }
    }
    
    console.log('Original records:', employee.daily.length, '| After deduplication:', uniqueDaily.length);
    console.log('Rendering', uniqueDaily.length, 'unique daily records');
    
    // DEBUG: Log Oct 27 specifically
    const oct27 = uniqueDaily.find(d => d.attendance_date === '2025-10-27');
    if (oct27) {
      console.log('Oct 27 data:', oct27);
      console.log('Oct 27 status field:', oct27.status);
    }
    
    body.innerHTML = uniqueDaily.map((day, index) => {
      // Check if this is an absent or on leave day (marked by backend)
      const isAbsent = day.status === 'absent' || (!day.login_time && !day.logout_time && day.status !== 'on_leave');
      const isOnLeave = day.status === 'on_leave';
      
      const loginTime = day.login_time || '-';
      const logoutTime = day.logout_time || '-';
      const hours = (isAbsent || isOnLeave) ? '-' : calculateHours(day.login_time, day.logout_time);
      // ⭐ 5-Oct-2026: the server's ONE-engine figure (frozen snapshot + manager waive) —
      //   what payroll counts. The browser calc is only a fallback for an older response.
      const lateBy = (isAbsent || isOnLeave) ? { duration: '-', isLate: false }
        : (day.late_minutes != null ? serverLateBy(day) : calculateLateBy(day.login_time, day.shift_start));
      // Server-supplied TARGET overtime for this day (the figure that earns bonus leave).
      // Replaces a client-side logout-vs-shift_end calculation that was a third, disagreeing
      // definition — and that silently defaulted to a 17:00 shift end when none was set.
      const otDayMins = Number(day.overtime_minutes) || 0;
      const overtime = (isAbsent || isOnLeave || otDayMins <= 0)
        ? { duration: '-', hasOvertime: false }
        : { duration: (otDayMins >= 60 ? `${Math.floor(otDayMins / 60)}h ${otDayMins % 60}m` : `${otDayMins}m`), hasOvertime: true };
      const status = isOnLeave ? 'On Leave' : (isAbsent ? 'Absent' : getStatus(day.login_time, day.shift_start));
      
      // Format date nicely
      const date = new Date(day.attendance_date + 'T00:00:00');
      const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
      const formattedDate = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

      const rowBg = index % 2 === 0 ? '#f9fafb' : 'white';
      const statusBg = status === 'On Time' ? '#dcfce7' : status === 'Late' ? '#fee2e2' : status === 'Absent' ? '#fef2f2' : status === 'On Leave' ? '#dbeafe' : '#f3f4f6';
      const statusColor = status === 'On Time' ? '#166534' : status === 'Late' ? '#991b1b' : status === 'Absent' ? '#991b1b' : status === 'On Leave' ? '#1e40af' : '#6b7280';
      
      // Meter values
      const meterStart = day.meter_start || null;
      const meterEnd = day.meter_end || null;
      const meterDistance = (meterStart && meterEnd) ? Math.abs(parseInt(meterEnd) - parseInt(meterStart)) : null;
      
      // Find previous day's meter_end to calculate gap
      // Records are sorted by date ASC in reports, so "previous day" is PREVIOUS item in array
      let prevMeterEnd = null;
      let meterGap = null;
      if (index > 0) {
        const prevRecord = uniqueDaily[index - 1];
        if (prevRecord && prevRecord.meter_end) {
          prevMeterEnd = prevRecord.meter_end;
          if (meterStart) {
            meterGap = parseInt(meterStart) - parseInt(prevMeterEnd);
          }
        }
      }
      
      // Build meter display with gap indicator
      let meterValuesHtml = '-';
      if (meterStart || meterEnd) {
        meterValuesHtml = `<div style="font-size: 11px; text-align: center;">`;
        if (meterStart && meterEnd) {
          meterValuesHtml += `<div style="color: #374151;">${meterStart} → ${meterEnd}</div>`;
          meterValuesHtml += `<div style="color: #16a34a; font-weight: 600;">${meterDistance} km</div>`;
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
        <tr style="background: ${rowBg}; transition: background 0.2s;" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='${rowBg}'">
          <td style="padding: 12px 16px; font-size: 14px; border-bottom: 1px solid #f3f4f6;">
            <div style="font-weight: 500; color: ${isOnLeave ? '#1e40af' : (isAbsent ? '#dc2626' : '#111827')};">${formattedDate}</div>
            <div style="font-size: 12px; color: #6b7280;">${dayName}</div>
          </td>
          <td style="padding: 12px 16px; font-size: 14px; color: ${(isAbsent || isOnLeave) ? '#9ca3af' : lateBy.isLate ? '#dc2626' : '#374151'}; font-weight: ${lateBy.isLate ? '600' : '400'}; border-bottom: 1px solid #f3f4f6;">${loginTime}</td>
          <td style="padding: 12px 16px; font-size: 14px; color: ${(isAbsent || isOnLeave) ? '#9ca3af' : '#374151'}; border-bottom: 1px solid #f3f4f6;">${logoutTime}</td>
          <td style="padding: 12px 16px; font-size: 14px; font-weight: 600; text-align: center; color: ${(isAbsent || isOnLeave) ? '#9ca3af' : '#111827'}; border-bottom: 1px solid #f3f4f6;">${hours}</td>
          <td style="padding: 12px 16px; font-size: 14px; text-align: center; color: ${lateBy.isLate ? '#dc2626' : '#9ca3af'}; font-weight: ${lateBy.isLate ? 'bold' : '400'}; border-bottom: 1px solid #f3f4f6;">${lateBy.duration}</td>
          <td style="padding: 12px 16px; font-size: 14px; text-align: center; color: ${overtime.hasOvertime ? '#16a34a' : '#9ca3af'}; font-weight: ${overtime.hasOvertime ? 'bold' : '400'}; border-bottom: 1px solid #f3f4f6;">${overtime.duration}</td>
          <td style="padding: 12px 16px; font-size: 14px; text-align: center; border-bottom: 1px solid #f3f4f6;">
            <span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 500; background: ${statusBg}; color: ${statusColor};">
              ${status}
            </span>
          </td>
          <td style="padding: 8px 12px; font-size: 14px; text-align: center; border-bottom: 1px solid #f3f4f6;">${meterValuesHtml}</td>
          <td style="padding: 8px 12px; font-size: 14px; text-align: center; border-bottom: 1px solid #f3f4f6;">${meterPicsHtml}</td>
        </tr>
      `;
    }).join('');
  }

  const modal = document.getElementById('dailyDetailsModal');
  if (!modal) {
    console.error('dailyDetailsModal not found in DOM!');
    alert('Error: Modal not found');
    return;
  }
  
  // Lock background scroll
  document.body.style.overflow = 'hidden';
  
  // Show modal with inline styles (overrides class="hidden")
  modal.classList.remove('hidden');
  modal.style.display = 'flex';
  
  console.log('Modal displayed');
  
  // Close on background click
  modal.onclick = function(e) {
    if (e.target === modal) {
      closeDailyDetails();
    }
  };
}

function closeDailyDetails() {
  const modal = document.getElementById('dailyDetailsModal');
  if (modal) {
    modal.classList.add('hidden');
    modal.style.display = 'none';
    // Restore background scroll
    document.body.style.overflow = '';
  }
}

function viewMeterPicture(imageUrl) {
  const modal = document.getElementById('meterPictureModal');
  const img = document.getElementById('meterPictureImage');
  if (modal && img) {
    img.src = imageUrl;
    modal.style.display = 'flex';
  }
}

function viewMeterPicturePath(relativePath) {
  const primary = `/storage/${relativePath}`;
  const fallback = `/public-storage/${relativePath}`;
  const modal = document.getElementById('meterPictureModal');
  const img = document.getElementById('meterPictureImage');
  if (modal && img) {
    img.onerror = function() {
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

function calculateHours(login, logout) {
  if (!login || !logout) return '-';
  const [lh, lm] = login.split(':').map(Number);
  const [oh, om] = logout.split(':').map(Number);
  const loginM = lh * 60 + lm;
  const logoutM = oh * 60 + om;
  const diff = logoutM - loginM;
  if (diff < 0) return '-';
  const h = Math.floor(diff / 60);
  const m = diff % 60;
  return `${h}h ${m}m`;
}

// A day's lateness as the server's engine gave it: what COUNTS, with any waive named.
// A late DAY stays late on the raw figure even when every minute was waived.
function serverLateBy(day) {
  const n = Number(day.late_minutes) || 0;
  const raw = (day.late_raw_minutes != null) ? Number(day.late_raw_minutes) : n;
  const w = Number(day.late_waived_minutes) || 0;
  const f = (m) => { const h = Math.floor(m / 60), mm = m % 60; return h > 0 ? `${h}h ${mm}m` : `${mm}m`; };
  if (raw <= 0) return { isLate: false, duration: '-' };
  return { isLate: true, duration: f(n) + (w > 0 ? ` (${f(w)} waived)` : '') };
}

function calculateLateBy(loginTime, shiftStart) {
  if (!loginTime) return { isLate: false, duration: '-' };
  try {
    const shift = shiftStart || '09:00';
    if (loginTime <= shift) return { isLate: false, duration: '-' };
    
    const [lh, lm] = loginTime.split(':').map(Number);
    const [sh, sm] = shift.split(':').map(Number);
    if (isNaN(lh) || isNaN(lm) || isNaN(sh) || isNaN(sm)) return { isLate: false, duration: '-' };
    
    const diff = (lh * 60 + lm) - (sh * 60 + sm);
    const h = Math.floor(diff / 60);
    const m = diff % 60;
    return { isLate: true, duration: h > 0 ? `${h}h ${m}m` : `${m}m` };
  } catch (e) {
    console.warn('Error calculating late by:', e);
    return { isLate: false, duration: '-' };
  }
}

function calculateOvertime(logoutTime, shiftEnd) {
  if (!logoutTime) return { hasOvertime: false, duration: '-' };
  try {
    const end = shiftEnd || '17:00';
    if (logoutTime <= end) return { hasOvertime: false, duration: '-' };
    
    const [lh, lm] = logoutTime.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    if (isNaN(lh) || isNaN(lm) || isNaN(eh) || isNaN(em)) return { hasOvertime: false, duration: '-' };
    
    const diff = (lh * 60 + lm) - (eh * 60 + em);
    if (diff <= 0) return { hasOvertime: false, duration: '-' };
    
    const h = Math.floor(diff / 60);
    const m = diff % 60;
    return { hasOvertime: true, duration: h > 0 ? `${h}h ${m}m` : `${m}m` };
  } catch (e) {
    console.warn('Error calculating overtime:', e);
    return { hasOvertime: false, duration: '-' };
  }
}

function getStatus(loginTime, shiftStart) {
  if (!loginTime) return '<span class="px-2 py-1 bg-gray-100 text-gray-600 rounded-full text-xs font-medium">Absent</span>';
  try {
    const shift = shiftStart || '09:00';
    if (loginTime > shift) return '<span class="px-2 py-1 bg-red-100 text-red-600 rounded-full text-xs font-medium">Late</span>';
    return '<span class="px-2 py-1 bg-green-100 text-green-600 rounded-full text-xs font-medium">On Time</span>';
  } catch (e) {
    console.warn('Error getting status:', e);
    return '<span class="px-2 py-1 bg-blue-100 text-blue-600 rounded-full text-xs font-medium">Present</span>';
  }
}

function exportToCSV() {
  let csv = 'Employee,Shift,Present Days,Attendance %,Absent,On Leave,Late Days,Avg Late (min),Late Waived (min),OT Days\n';
  
  reportData.forEach(emp => {
    const userWorkingDays = emp.working_days || calculatedWorkingDays || 27;
    const attendancePerc = userWorkingDays > 0 ? ((emp.present_days / userWorkingDays) * 100).toFixed(1) : 0;
    const avgLate = emp.late_days > 0 ? (emp.total_late_minutes / emp.late_days).toFixed(0) : 0;
    // Net, plus what a manager forgave — the average above is of the NET minutes.
    const lateWaived = emp.late_waived_minutes || 0;
    csv += `${emp.fullname},${emp.shift_name || 'Default'},${emp.present_days}/${userWorkingDays},${attendancePerc}%,${emp.absent_days || 0},${emp.leave_days || 0},${emp.late_days},${avgLate},${lateWaived},${emp.overtime_days}\n`;
  });

  const blob = new Blob([csv], { type: 'text/csv' });
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `attendance_report_${currentMonth}.csv`;
  a.click();
}

// Working Days Configuration Functions
function toggleWorkingDaysConfig() {
  const config = document.getElementById('workingDaysConfig');
  if (config.style.display === 'none' || config.classList.contains('hidden')) {
    config.style.display = 'block';
    config.classList.remove('hidden');
  } else {
    config.style.display = 'none';
    config.classList.add('hidden');
  }
  calculateWorkingDays();
}

function calculateWorkingDays() {
  if (!currentMonth) {
    console.log('No currentMonth set yet');
    return;
  }
  
  // Read current checkbox state
  const selectedDays = [];
  ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].forEach((day, index) => {
    const checkbox = document.getElementById(`day_${day}`);
    if (checkbox && checkbox.checked) {
      // Convert to JS day (0=Sun, 1=Mon, etc.)
      const jsDay = index === 6 ? 0 : index + 1;
      selectedDays.push(jsDay);
    }
  });
  
  console.log('Selected days:', selectedDays);
  
  const [year, month] = currentMonth.split('-');
  const daysInMonth = new Date(year, month, 0).getDate();
  let count = 0;
  
  for (let day = 1; day <= daysInMonth; day++) {
    const date = new Date(year, month - 1, day);
    const dayOfWeek = date.getDay();
    const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    
    // Check if it's a working day and not a holiday
    if (selectedDays.includes(dayOfWeek) && !holidays.includes(dateStr)) {
      count++;
    }
  }
  
  console.log('Calculated working days:', count);
  calculatedWorkingDays = count;
  const countEl = document.getElementById('workingDaysCount');
  if (countEl) {
    countEl.textContent = count;
  }
}

function applyWorkingDaysConfig() {
  // Get selected working days
  workingDays = [];
  ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].forEach((day, index) => {
    const checkbox = document.getElementById(`day_${day}`);
    if (checkbox && checkbox.checked) {
      // Convert to JS day (0=Sun, 1=Mon, etc.)
      const jsDay = index === 6 ? 0 : index + 1;
      workingDays.push(jsDay);
    }
  });
  
  // Save configuration to localStorage
  const config = {
    workingDays: workingDays,
    holidays: holidays
  };
  localStorage.setItem('attendanceWorkingDaysConfig', JSON.stringify(config));
  console.log('Saved configuration:', config);
  
  calculateWorkingDays();
  loadMonthlyReport(); // Reload with new calculation
  
  // Show success message
  alert('✓ Working days configuration saved! This will be used for all future calculations.');
}

function addHoliday() {
  const dateInput = document.getElementById('holidayDate');
  const dateStr = dateInput.value;
  
  if (!dateStr) {
    alert('Please select a date');
    return;
  }
  
  if (holidays.includes(dateStr)) {
    alert('This date is already added');
    return;
  }
  
  holidays.push(dateStr);
  
  // Save to localStorage immediately
  const config = {
    workingDays: workingDays,
    holidays: holidays
  };
  localStorage.setItem('attendanceWorkingDaysConfig', JSON.stringify(config));
  
  renderHolidays();
  calculateWorkingDays();
  dateInput.value = '';
}

function removeHoliday(dateStr) {
  holidays = holidays.filter(h => h !== dateStr);
  
  // Save to localStorage immediately
  const config = {
    workingDays: workingDays,
    holidays: holidays
  };
  localStorage.setItem('attendanceWorkingDaysConfig', JSON.stringify(config));
  
  renderHolidays();
  calculateWorkingDays();
}

function renderHolidays() {
  const list = document.getElementById('holidaysList');
  if (!list) return;
  
  if (holidays.length === 0) {
    list.innerHTML = '<p class="text-sm text-gray-500 italic">No holidays added</p>';
    return;
  }
  
  list.innerHTML = holidays.map(date => {
    const formatted = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    return `
      <div class="flex items-center justify-between p-2 bg-red-50 border border-red-200 rounded text-sm">
        <span class="text-red-700">${formatted}</span>
        <button onclick="removeHoliday('${date}')" class="text-red-600 hover:text-red-800 font-bold">&times;</button>
      </div>
    `;
  }).join('');
}
</script>
@endsection

