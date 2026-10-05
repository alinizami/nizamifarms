@extends('layouts.app')

@section('title', 'Generate Salary Slip')

@section('content')

<!-- Container -->
<div class="kt-container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        <!-- Step 1: Select Employee & View Monthly Calendar -->
        <div class="kt-card" id="step-1">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Step 1: Select Employee</h3>
            </div>
            <div class="kt-card-body">
                <div class="max-w-md mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Employee *</label>
                    <select id="employee-select" class="kt-select w-full" onchange="loadEmployeeSalaryCalendar()">
                        <option value="">Select Employee...</option>
                        @php
                            $employees = \DB::table('t_sys_user as u')
                                ->leftJoin('t_hr_employee_profile as p', 'u.id', '=', 'p.user_id')
                                ->where('u.is_active', 1)
                                ->whereNotNull('p.id')
                                ->where('p.is_active', 1)
                                ->select('u.id', 'u.fullname', 'p.employee_code')
                                ->orderBy('u.fullname')
                                ->get();
                        @endphp
                        @foreach($employees as $emp)
                            <option value="{{ $emp->id }}">{{ $emp->fullname }} @if($emp->employee_code)({{ $emp->employee_code }})@endif</option>
                        @endforeach
                    </select>
                </div>

                <!-- Monthly Salary Calendar (Hidden initially) -->
                <div id="salary-calendar" class="hidden">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-lg font-semibold">Salary Calendar</h4>
                        <div class="text-sm text-gray-600">
                            <span id="calendar-year">Current + Last 12 Months</span>
                        </div>
                    </div>

                    <!-- Loading State -->
                    <div id="calendar-loading" class="text-center py-8">
                        <i class="ki-filled ki-loading animate-spin text-2xl text-gray-400"></i>
                        <p class="text-gray-500 mt-2">Loading salary records...</p>
                    </div>

                    <!-- Calendar Grid -->
                    <div id="calendar-grid" class="hidden grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <!-- Months will be populated by JavaScript -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 2: Review & Adjust (Hidden initially) -->
        <div class="kt-card hidden" id="step-2">
            <div class="kt-card-header">
                <div class="flex items-center justify-between w-full">
                    <h3 class="kt-card-title">Step 2: Review & Adjust Salary</h3>
                    <button onclick="resetForm()" class="kt-btn kt-btn-sm kt-btn-light">
                        <i class="ki-filled ki-arrows-circle"></i> Start Over
                    </button>
                </div>
            </div>
            <div class="kt-card-body">
                <!-- Employee & Month Info -->
                <div class="p-4 bg-blue-50 rounded-lg mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="font-semibold text-lg" id="review-employee-name"></div>
                            <div class="text-sm text-gray-600" id="review-month"></div>
                        </div>
                        <div id="review-employee-code" class="text-sm font-medium text-blue-600"></div>
                    </div>
                </div>

                <!-- Main Grid: Earnings, Deductions, Summary -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    
                    <!-- Earnings Column -->
                    <div class="space-y-4">
                        <h4 class="font-semibold text-green-700 text-lg border-b pb-2">💰 Earnings</h4>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Base Salary (PKR)</label>
                            <input type="number" id="base-salary" step="0.01" class="kt-input w-full" readonly>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Overtime Hours
                                <button type="button" onclick="toggleOverride('overtime')" class="text-xs text-blue-600 ml-2">
                                    <i class="ki-filled ki-lock" id="overtime-lock"></i> Override
                                </button>
                            </label>
                            <div class="flex gap-2">
                                <input type="number" id="overtime-hours" step="0.01" class="kt-input w-1/2" readonly>
                                <input type="number" id="overtime-amount" step="0.01" class="kt-input w-1/2 font-medium text-green-600" readonly>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Bonuses</label>
                            <input type="number" id="bonuses" step="0.01" class="kt-input w-full text-green-600" placeholder="0.00">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Allowances</label>
                            <input type="number" id="allowances" step="0.01" class="kt-input w-full text-green-600" placeholder="0.00">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Other Earnings</label>
                            <input type="number" id="other-earnings" step="0.01" class="kt-input w-full text-green-600" placeholder="0.00">
                            <input type="text" id="other-earnings-desc" class="kt-input w-full mt-1 text-xs" placeholder="Description...">
                        </div>
                        
                        <div class="pt-3 border-t">
                            <div class="flex items-center justify-between font-bold text-lg">
                                <span>Gross Salary:</span>
                                <span class="text-green-600" id="gross-salary-display">PKR 0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Deductions Column -->
                    <div class="space-y-4">
                        <h4 class="font-semibold text-red-700 text-lg border-b pb-2">➖ Deductions</h4>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Late Minutes
                                <button type="button" onclick="toggleOverride('late')" class="text-xs text-blue-600 ml-2">
                                    <i class="ki-filled ki-lock" id="late-lock"></i> Override
                                </button>
                            </label>
                            {{-- Sep-2026 — a manager may have forgiven some of this month's
                                 lateness. Without this line the field shows a smaller number
                                 than the day rows below it add up to, with nothing to explain
                                 the difference. --}}
                            <div id="late-waived-note" style="display:none;font-size:11px;color:#B45309;font-weight:600;margin-bottom:4px;"></div>
                            <div class="flex gap-2">
                                <input type="number" id="late-minutes" step="0.01" class="kt-input w-1/2" readonly>
                                <input type="number" id="late-deduction" step="0.01" class="kt-input w-1/2 font-medium text-red-600" readonly>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Absent Days
                                <button type="button" onclick="toggleOverride('absent')" class="text-xs text-blue-600 ml-2">
                                    <i class="ki-filled ki-lock" id="absent-lock"></i> Override
                                </button>
                            </label>
                            <div class="flex gap-2">
                                <input type="number" id="absent-days" class="kt-input w-1/2" readonly>
                                <input type="number" id="absent-deduction" step="0.01" class="kt-input w-1/2 font-medium text-red-600" readonly>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 flex items-center justify-between">
                                <span>Salary Advance</span>
                                <button type="button" onclick="toggleOverride('advance')" class="text-xs text-purple-600 hover:text-purple-700" id="advance-override-btn">
                                    <i class="ki-filled ki-pencil"></i> Override
                                </button>
                            </label>
                            <input type="number" id="salary-advance" step="0.01" class="kt-input w-full text-red-600" readonly>
                            <div class="text-xs text-gray-500 mt-1" id="advance-info"></div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 flex items-center justify-between">
                                <span>Loan Installment</span>
                                <button type="button" onclick="toggleOverride('loan')" class="text-xs text-gray-800 hover:text-gray-900 font-medium" id="loan-override-btn">
                                    <i class="ki-filled ki-pencil"></i> Override/Skip
                                </button>
                            </label>
                            <input type="number" id="loan-installment" step="0.01" class="kt-input w-full text-red-600" readonly>
                            <div class="text-xs text-gray-500 mt-1" id="loan-info"></div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tax Deduction</label>
                            <input type="number" id="tax-deduction" step="0.01" class="kt-input w-full text-red-600" placeholder="0.00">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Other Deductions</label>
                            <input type="number" id="other-deductions" step="0.01" class="kt-input w-full text-red-600" placeholder="0.00">
                            <input type="text" id="other-deductions-desc" class="kt-input w-full mt-1 text-xs" placeholder="Description...">
                        </div>
                        
                        <div class="pt-3 border-t">
                            <div class="flex items-center justify-between font-bold text-lg">
                                <span>Total Deductions:</span>
                                <span class="text-red-600" id="total-deductions-display">PKR 0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Summary Column -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between border-b pb-2">
                            <h4 class="font-semibold text-blue-700 text-lg">📊 Attendance Summary</h4>
                            <button type="button" onclick="openAttendanceReport()" class="text-xs px-3 py-1.5 bg-purple-100 hover:bg-purple-200 text-purple-700 hover:text-purple-900 font-bold rounded shadow-sm transition-all border border-purple-300">
                                <i class="ki-filled ki-eye"></i> 👁️ View Report
                            </button>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div class="p-3 bg-gray-50 rounded">
                                <div class="text-gray-600">Working Days</div>
                                <div class="font-bold text-lg" id="working-days">0</div>
                            </div>
                            <div class="p-3 bg-green-50 rounded">
                                <div class="text-gray-600">Present Days</div>
                                <div class="font-bold text-lg text-green-600" id="present-days">0</div>
                            </div>
                            <div class="p-3 bg-red-50 rounded">
                                <div class="text-gray-600">Absent Days</div>
                                <div class="font-bold text-lg text-red-600" id="absent-days">0</div>
                            </div>
                            <div class="p-3 bg-blue-50 rounded">
                                <div class="text-gray-600">On Leave</div>
                                <div class="font-bold text-lg text-blue-600" id="leave-days">0</div>
                            </div>
                        </div>

                        <div class="pt-4 border-t">
                            <h4 class="font-semibold text-purple-700 text-lg mb-3">💵 Net Salary</h4>
                            <div class="p-4 bg-gradient-to-r from-purple-50 to-blue-50 rounded-lg">
                                <div class="text-sm text-gray-600 mb-1">Amount to Pay</div>
                                <div class="font-bold text-3xl text-purple-600" id="net-salary-display">PKR 0.00</div>
                            </div>
                        </div>

                        <!-- Adjustments Info -->
                        <div id="adjustments-info" class="hidden p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                            <div class="text-sm font-medium text-yellow-800 mb-2">
                                <i class="ki-filled ki-information-2"></i> Manual Adjustments Made
                            </div>
                            <div class="text-xs text-yellow-700" id="adjustments-list"></div>
                        </div>

                        <!-- Override Notes -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Override/Adjustment Notes</label>
                            <textarea id="override-notes" rows="3" class="kt-input w-full text-sm" 
                                      placeholder="Explain any manual overrides or adjustments made..."></textarea>
                        </div>

                        <!-- Action Buttons -->
                        <div class="space-y-2 pt-4">
                            <button onclick="saveSalarySlip('draft')" class="kt-btn kt-btn-secondary w-full">
                                <i class="ki-filled ki-save"></i> Save as Draft
                            </button>
                            @if(auth()->user()->hasPermission('approve_salary_slips'))
                            <button onclick="saveSalarySlip('approved')" class="kt-btn kt-btn-success w-full">
                                <i class="ki-filled ki-check-circle"></i> Approve & Finalize
                            </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Attendance Details Modal -->
<div id="salaryAttendanceModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px;">
  <div id="salaryAttendanceCard" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 1000px; height: 85vh; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden;" onclick="event.stopPropagation();">
      
      <!-- Header -->
      <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
          <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 50%; background: #dbeafe; display: flex; align-items: center; justify-content: center; color: #2563eb; font-size: 18px; font-weight: bold;">
              👤
            </div>
            <div>
              <h3 style="font-size: 18px; font-weight: 600; color: #111827; margin: 0;" id="salaryModalEmployeeName">Employee Name</h3>
              <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;" id="salaryModalMonthYear">Month Year</p>
            </div>
          </div>
          <button type="button" onclick="closeSalaryAttendanceModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
      </div>

      <!-- Stats Bar -->
      <div style="padding: 12px 24px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; flex-shrink: 0;">
        <div style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px;">
          <div style="text-align: center;">
            <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Present</p>
            <p style="font-size: 18px; font-weight: bold; color: #111827; margin: 0;" id="salaryModalStatPresent">0</p>
          </div>
          <div style="text-align: center;">
            <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Absent</p>
            <p style="font-size: 18px; font-weight: bold; color: #dc2626; margin: 0;" id="salaryModalStatAbsent">0</p>
          </div>
          <div style="text-align: center;">
            <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">On Leave</p>
            <p style="font-size: 18px; font-weight: bold; color: #3b82f6; margin: 0;" id="salaryModalStatLeave">0</p>
          </div>
          <div style="text-align: center;">
            <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Late</p>
            <p style="font-size: 18px; font-weight: bold; color: #f59e0b; margin: 0;" id="salaryModalStatLate">0</p>
            <p style="font-size: 9px; color: #f59e0b; margin: 4px 0 0 0;" id="salaryModalStatLateHours">0h 0m</p>
          </div>
          <div style="text-align: center;">
            <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Overtime</p>
            <p style="font-size: 18px; font-weight: bold; color: #16a34a; margin: 0;" id="salaryModalStatOT">0</p>
            <p style="font-size: 9px; color: #16a34a; margin: 4px 0 0 0;" id="salaryModalStatOTHours">0h 0m</p>
          </div>
          <div style="text-align: center;">
            <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Total Hours</p>
            <p style="font-size: 18px; font-weight: bold; color: #111827; margin: 0;" id="salaryModalStatHours">0h</p>
          </div>
        </div>
      </div>

      <!-- Scrollable Table Container -->
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
            </tr>
          </thead>
          <tbody id="salaryDailyDetailsBody" style="background: white;">
            <!-- Populated by JS -->
          </tbody>
        </table>
      </div>

      <!-- Footer -->
      <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: white; flex-shrink: 0; display: flex; justify-content: flex-end;">
        <button 
          type="button"
          onclick="closeSalaryAttendanceModal()" 
          style="padding: 10px 24px; background: #2563eb; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);"
        >
          Close
        </button>
      </div>

  </div>
</div>

@endsection

@push('demo1_js')
<script>
let calculatedData = null;
let currentUserId = null;  // For attendance report link
let currentMonth = null;   // For attendance report link (format: YYYY-MM)
let currentYear = new Date().getFullYear();
let employeeSalarySlips = [];
let overrides = {
    overtime: false,
    late: false,
    absent: false,
    advance: false,
    loan: false
};

// Load employee salary calendar
function loadEmployeeSalaryCalendar() {
    const userId = document.getElementById('employee-select').value;
    
    if (!userId) {
        document.getElementById('salary-calendar').classList.add('hidden');
        return;
    }
    
    // Show calendar and loading state
    document.getElementById('salary-calendar').classList.remove('hidden');
    document.getElementById('calendar-loading').classList.remove('hidden');
    document.getElementById('calendar-grid').classList.add('hidden');
    
    currentUserId = userId;
    
    // Fetch employee salary slips
    fetch(`{{ url('/hr/employees') }}/${userId}/salary-slips`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                employeeSalarySlips = data.slips;
                console.log('Loaded salary slips:', employeeSalarySlips);
                renderSalaryCalendar();
            } else {
                alert('Error loading salary records');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading salary records');
        });
}

// Render salary calendar for current month + 12 months past
function renderSalaryCalendar() {
    document.getElementById('calendar-loading').classList.add('hidden');
    document.getElementById('calendar-grid').classList.remove('hidden');
    
    const months = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];
    
    // Get current month and calculate 12 months range
    const now = new Date();
    const currentMonth = now.getMonth(); // 0-11
    const currentYearNow = now.getFullYear();
    
    // Build array of months to display (current month + 12 months back)
    let monthsToDisplay = [];
    for (let i = 0; i <= 12; i++) {
        const date = new Date(currentYearNow, currentMonth - i, 1);
        monthsToDisplay.push({
            year: date.getFullYear(),
            month: date.getMonth(),
            monthName: months[date.getMonth()],
            // Use 2nd of month to prevent timezone shift issues (UTC conversion won't cross month boundary)
            monthKey: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-02`
        });
    }
    
    // Update year display to show range
    const oldestMonth = monthsToDisplay[monthsToDisplay.length - 1];
    const rangeText = oldestMonth.year === currentYearNow 
        ? `${months[oldestMonth.month]} - ${months[currentMonth]} ${currentYearNow}`
        : `${months[oldestMonth.month]} ${oldestMonth.year} - ${months[currentMonth]} ${currentYearNow}`;
    document.getElementById('calendar-year').textContent = rangeText;
    
    let html = '';
    
    monthsToDisplay.forEach(monthData => {
        const { year, monthName, monthKey } = monthData;
        
        // Find existing slip for this month (compare YYYY-MM only to handle both old -01 and new -02 dates)
        const monthPrefix = monthKey.substring(0, 7); // Extract YYYY-MM
        const existingSlip = employeeSalarySlips.find(slip => slip.salary_month && slip.salary_month.substring(0, 7) === monthPrefix);
        
        // Debug logging
        if (monthName === 'October' && year === 2025) {
            console.log('October 2025 check:', {
                monthKey: monthKey,
                existingSlip: existingSlip,
                allSlips: employeeSalarySlips.map(s => ({ month: s.salary_month, id: s.id }))
            });
        }
        
        const statusColors = {
            'draft': 'border-gray-300 bg-gray-50',
            'approved': 'border-blue-300 bg-blue-50',
            'paid': 'border-green-300 bg-green-50',
            'cancelled': 'border-red-300 bg-red-50'
        };
        
        const statusBadges = {
            'draft': '<span class="kt-badge kt-badge-sm kt-badge-secondary">Draft</span>',
            'approved': '<span class="kt-badge kt-badge-sm kt-badge-primary">Approved</span>',
            'paid': '<span class="kt-badge kt-badge-sm kt-badge-success">Paid</span>',
            'cancelled': '<span class="kt-badge kt-badge-sm kt-badge-danger">Cancelled</span>'
        };
        
        const borderClass = existingSlip ? statusColors[existingSlip.slip_status] : 'border-gray-200 bg-white';
        
        html += `
            <div class="border ${borderClass} rounded-lg p-4">
                <div class="flex items-center justify-between mb-3">
                    <div class="font-semibold text-gray-900">${monthName} ${year}</div>
                    ${existingSlip ? statusBadges[existingSlip.slip_status] : ''}
                </div>
                
                ${existingSlip ? `
                    <div class="text-sm text-gray-600 mb-3">
                        <div>Slip #${existingSlip.slip_number || 'N/A'}</div>
                        <div class="font-semibold text-green-600">Net: ${formatCurrency(existingSlip.net_salary)}</div>
                    </div>
                    <div class="flex gap-2">
                        <a href="/hr/salary-slips/${existingSlip.id}" class="kt-btn kt-btn-sm kt-btn-light flex-1">
                            <i class="ki-filled ki-eye"></i> View
                        </a>
                        ${existingSlip.slip_status !== 'cancelled' ? `
                            <button onclick="confirmDeleteSlip(${existingSlip.id}, '${monthName} ${year}')" 
                                    class="kt-btn kt-btn-sm kt-btn-danger flex-1"
                                    style="background-color: #dc2626 !important; color: white !important;">
                                <i class="ki-filled ki-trash"></i> Delete
                            </button>
                        ` : ''}
                    </div>
                ` : `
                    <div class="text-sm text-gray-500 mb-3">
                        No salary slip generated
                    </div>
                    <button onclick="generateForMonth('${monthKey}')" class="kt-btn kt-btn-sm kt-btn-primary w-full">
                        <i class="ki-filled ki-plus"></i> Generate Salary
                    </button>
                `}
            </div>
        `;
    });
    
    document.getElementById('calendar-grid').innerHTML = html;
}

// Generate salary for specific month
function generateForMonth(monthKey) {
    currentMonth = monthKey.substring(0, 7); // YYYY-MM format
    calculateSalary(monthKey);
}

// Confirm delete salary slip
function confirmDeleteSlip(slipId, monthName) {
    if (!confirm(`⚠️ WARNING: Delete Salary Slip for ${monthName}?\n\nThis will:\n✓ Delete the salary slip\n✓ Reverse ledger entries\n✓ Restore account balances\n✓ Rollback loan installments\n✓ Unsettle salary advances\n\nThis action cannot be undone. Continue?`)) {
        return;
    }
    
    fetch(`/hr/salary-slips/${slipId}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            // Reload calendar
            loadEmployeeSalaryCalendar();
        } else {
            alert('❌ Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('❌ Error deleting salary slip');
    });
}

// Pre-fill employee and load calendar if user_id is in URL
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const userId = urlParams.get('user_id');
    
    if (userId) {
        document.getElementById('employee-select').value = userId;
        // Trigger calendar load
        loadEmployeeSalaryCalendar();
    }
    
    // Add event listeners for live calculation
    const inputs = ['base-salary', 'overtime-hours', 'overtime-amount', 'bonuses', 'allowances', 'other-earnings',
                    'late-minutes', 'late-deduction', 'absent-days', 'absent-deduction', 
                    'salary-advance', 'loan-installment', 'tax-deduction', 'other-deductions'];
    
    inputs.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', updateTotals);
        }
    });
});

function calculateSalary(monthKey = null) {
    const userId = currentUserId || document.getElementById('employee-select').value;
    const month = monthKey || (document.getElementById('salary-month') ? document.getElementById('salary-month').value : null);
    
    if (!userId || !month) {
        alert('Please select both employee and month');
        return;
    }
    
    // Show loading (only if button exists)
    const btn = event && event.target ? event.target : null;
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="ki-filled ki-loading animate-spin"></i> Calculating...';
    }
    
    fetch('{{ route("hr.salary-slips.calculate") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            user_id: userId,
            month: monthKey || (month + '-02')  // Use 2nd of month to prevent timezone shift issues
        })
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(err => {
                throw new Error(err.error || err.message || 'Failed to calculate salary');
            });
        }
        return response.json();
    })
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="ki-filled ki-calculator"></i> Calculate Salary';
        }
        
        if (data.success) {
            calculatedData = data;
            
            // Store for attendance report link
            currentUserId = userId;
            currentMonth = monthKey ? monthKey.substring(0, 7) : month; // YYYY-MM format
            
            populateForm(data);
            document.getElementById('step-1').classList.add('hidden');
            document.getElementById('step-2').classList.remove('hidden');
        } else {
            // Improved error message
            const errorMsg = data.error || data.message || 'Failed to calculate salary';
            if (errorMsg.includes('already exists')) {
                alert('⚠️ Salary Already Generated\n\n' + errorMsg + '\n\nRefreshing calendar to show existing slip...');
                // Reload calendar to show the existing slip
                loadEmployeeSalaryCalendar();
            } else {
                alert('❌ Error: ' + errorMsg);
            }
        }
    })
    .catch(error => {
        console.error('Error calculating salary:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="ki-filled ki-calculator"></i> Calculate Salary';
        }
        alert('❌ Error calculating salary: ' + error.message);
    });
}

function populateForm(data) {
    // Header info
    document.getElementById('review-employee-name').textContent = data.employee.fullname;
    document.getElementById('review-month').textContent = formatMonth(data.salary_month);
    document.getElementById('review-employee-code').textContent = data.profile.employee_code || '';
    
    // Earnings
    document.getElementById('base-salary').value = parseFloat(data.base_salary).toFixed(2);
    document.getElementById('overtime-hours').value = parseFloat(data.overtime_hours).toFixed(2);
    document.getElementById('overtime-amount').value = parseFloat(data.overtime_amount).toFixed(2);
    document.getElementById('bonuses').value = parseFloat(data.bonuses || 0).toFixed(2);
    document.getElementById('allowances').value = parseFloat(data.allowances || 0).toFixed(2);
    document.getElementById('other-earnings').value = parseFloat(data.other_earnings || 0).toFixed(2);
    
    // Deductions
    document.getElementById('late-minutes').value = parseFloat(data.late_minutes).toFixed(2);
    document.getElementById('late-deduction').value = parseFloat(data.late_deduction).toFixed(2);
    // ⭐ Sep-2026 — `late_minutes` above is NET of any minutes a manager waived. Carry the
    // split through so the frozen slip can still explain a reduced figure, and say so on
    // screen rather than leaving a smaller number with no cause.
    window.__lateSplit = {
      waived: Number(data.late_waived_minutes || 0),
      raw: Number(data.late_raw_minutes || data.late_minutes || 0),
    };
    const lateNote = document.getElementById('late-waived-note');
    if (lateNote) {
      lateNote.textContent = window.__lateSplit.waived > 0
        ? `${window.__lateSplit.raw} min late · ${window.__lateSplit.waived} min waived by a manager · ${Math.round(Number(data.late_minutes))} min counted`
        : '';
      lateNote.style.display = window.__lateSplit.waived > 0 ? 'block' : 'none';
    }
    document.getElementById('absent-days').value = data.absent_days;
    document.getElementById('absent-deduction').value = parseFloat(data.absent_deduction).toFixed(2);
    document.getElementById('salary-advance').value = parseFloat(data.salary_advance).toFixed(2);
    document.getElementById('loan-installment').value = parseFloat(data.loan_installment).toFixed(2);
    document.getElementById('tax-deduction').value = parseFloat(data.tax_deduction || 0).toFixed(2);
    document.getElementById('other-deductions').value = parseFloat(data.other_deductions || 0).toFixed(2);
    
    // Attendance summary
    document.getElementById('working-days').textContent = data.working_days;
    document.getElementById('present-days').textContent = data.present_days;
    document.getElementById('absent-days').textContent = data.absent_days || 0;
    document.getElementById('leave-days').textContent = data.leave_days;
    
    // Info texts
    if (data.advance_info) {
        document.getElementById('advance-info').textContent = data.advance_info;
    }
    if (data.loan_info) {
        document.getElementById('loan-info').textContent = data.loan_info;
    }
    // Always show the override button for consistency
    document.getElementById('loan-override-btn').classList.remove('hidden');
    
    updateTotals();
}

function toggleOverride(type) {
    overrides[type] = !overrides[type];
    
    const btn = document.getElementById(`${type}-override-btn`);
    if (btn) {
        if (type === 'loan') {
            btn.classList.toggle('text-gray-800');
            btn.classList.toggle('text-green-600');
        } else {
            btn.classList.toggle('text-purple-600');
            btn.classList.toggle('text-green-600');
        }
        btn.textContent = overrides[type] ? '✓ Overridden' : (type === 'loan' ? '✎ Override/Skip' : '✎ Override');
    }
    
    if (type === 'overtime') {
        document.getElementById('overtime-hours').readOnly = !overrides[type];
        document.getElementById('overtime-amount').readOnly = !overrides[type];
    } else if (type === 'late') {
        document.getElementById('late-minutes').readOnly = !overrides[type];
        document.getElementById('late-deduction').readOnly = !overrides[type];
    } else if (type === 'absent') {
        const absentDaysInput = document.getElementById('absent-days');
        const absentDeductionInput = document.getElementById('absent-deduction');
        
        absentDaysInput.readOnly = !overrides[type];
        absentDeductionInput.readOnly = !overrides[type];
        
        if (overrides[type]) {
            // When override is enabled, setup auto-calculation on absent days change
            absentDaysInput.addEventListener('input', autoCalculateAbsentDeduction);
        } else {
            // When override is disabled, remove the listener and restore original value
            absentDaysInput.removeEventListener('input', autoCalculateAbsentDeduction);
            if (calculatedData) {
                absentDaysInput.value = calculatedData.absent_days || '0';
                absentDeductionInput.value = calculatedData.absent_deduction || '0.00';
            }
        }
    } else if (type === 'advance') {
        document.getElementById('salary-advance').readOnly = !overrides[type];
        if (!overrides[type] && calculatedData) {
            document.getElementById('salary-advance').value = calculatedData.salary_advance || '0.00';
        }
    } else if (type === 'loan') {
        document.getElementById('loan-installment').readOnly = !overrides[type];
        if (overrides[type]) {
            // Allow editing - default to 0 but user can change
            if (!document.getElementById('loan-installment').value || document.getElementById('loan-installment').value == calculatedData?.loan_installment) {
                document.getElementById('loan-installment').value = '0.00';
            }
        } else if (calculatedData) {
            document.getElementById('loan-installment').value = calculatedData.loan_installment || '0.00';
        }
    }
    
    updateTotals();
    updateAdjustmentsInfo();
}

function updateTotals() {
    // Calculate gross salary
    const baseSalary = parseFloat(document.getElementById('base-salary').value) || 0;
    const overtimeAmount = parseFloat(document.getElementById('overtime-amount').value) || 0;
    const bonuses = parseFloat(document.getElementById('bonuses').value) || 0;
    const allowances = parseFloat(document.getElementById('allowances').value) || 0;
    const otherEarnings = parseFloat(document.getElementById('other-earnings').value) || 0;
    
    const grossSalary = baseSalary + overtimeAmount + bonuses + allowances + otherEarnings;
    document.getElementById('gross-salary-display').textContent = formatCurrency(grossSalary);
    
    // Calculate total deductions
    const lateDeduction = parseFloat(document.getElementById('late-deduction').value) || 0;
    const absentDeduction = parseFloat(document.getElementById('absent-deduction').value) || 0;
    const salaryAdvance = parseFloat(document.getElementById('salary-advance').value) || 0;
    const loanInstallment = parseFloat(document.getElementById('loan-installment').value) || 0;
    const taxDeduction = parseFloat(document.getElementById('tax-deduction').value) || 0;
    const otherDeductions = parseFloat(document.getElementById('other-deductions').value) || 0;
    
    const totalDeductions = lateDeduction + absentDeduction + salaryAdvance + loanInstallment + taxDeduction + otherDeductions;
    document.getElementById('total-deductions-display').textContent = formatCurrency(totalDeductions);
    
    // Calculate net salary
    const netSalary = grossSalary - totalDeductions;
    document.getElementById('net-salary-display').textContent = formatCurrency(netSalary);
}

function updateAdjustmentsInfo() {
    const adjustments = [];
    
    if (overrides.overtime) adjustments.push('Overtime manually adjusted');
    if (overrides.late) adjustments.push('Late deduction overridden');
    if (overrides.absent) adjustments.push('Absent deduction adjusted');
    if (overrides.advance) adjustments.push('Salary advance overridden');
    if (overrides.loan) adjustments.push('Loan installment skipped/overridden');
    
    const infoDiv = document.getElementById('adjustments-info');
    const listDiv = document.getElementById('adjustments-list');
    
    if (adjustments.length > 0) {
        infoDiv.classList.remove('hidden');
        listDiv.innerHTML = adjustments.map(a => `• ${a}`).join('<br>');
    } else {
        infoDiv.classList.add('hidden');
    }
}

function saveSalarySlip(status) {
    const userId = currentUserId || document.getElementById('employee-select').value;
    const month = currentMonth ? currentMonth + '-02' : null;  // Use 2nd of month to prevent timezone shift
    
    if (!userId || !month) {
        alert('❌ Error: Missing employee or month information');
        return;
    }
    
    const data = {
        user_id: userId,
        salary_month: month,
        
        // Earnings
        base_salary: parseFloat(document.getElementById('base-salary').value) || 0,
        overtime_hours: parseFloat(document.getElementById('overtime-hours').value) || 0,
        overtime_amount: parseFloat(document.getElementById('overtime-amount').value) || 0,
        bonuses: parseFloat(document.getElementById('bonuses').value) || 0,
        allowances: parseFloat(document.getElementById('allowances').value) || 0,
        other_earnings: parseFloat(document.getElementById('other-earnings').value) || 0,
        other_earnings_desc: document.getElementById('other-earnings-desc').value,
        
        // Deductions
        late_minutes: parseFloat(document.getElementById('late-minutes').value) || 0,
        // The split travels with it, so the frozen slip can account for a reduced figure.
        late_waived_minutes: Math.round((window.__lateSplit && window.__lateSplit.waived) || 0),
        late_raw_minutes: Math.round((window.__lateSplit && window.__lateSplit.raw) || 0),
        late_deduction: parseFloat(document.getElementById('late-deduction').value) || 0,
        absent_days: parseInt(document.getElementById('absent-days').value) || 0,
        absent_deduction: parseFloat(document.getElementById('absent-deduction').value) || 0,
        salary_advance: parseFloat(document.getElementById('salary-advance').value) || 0,
        loan_installment: parseFloat(document.getElementById('loan-installment').value) || 0,
        tax_deduction: parseFloat(document.getElementById('tax-deduction').value) || 0,
        other_deductions: parseFloat(document.getElementById('other-deductions').value) || 0,
        other_deductions_desc: document.getElementById('other-deductions-desc').value,
        
        // Attendance
        working_days: parseInt(document.getElementById('working-days')?.textContent) || 0,
        present_days: parseInt(document.getElementById('present-days')?.textContent) || 0,
        leave_days: parseInt(document.getElementById('leave-days')?.textContent) || 0,
        half_days: parseInt(document.getElementById('half-days')?.textContent) || 0,
        
        // Overrides
        late_deduction_overridden: overrides.late ? 1 : 0,
        overtime_overridden: overrides.overtime ? 1 : 0,
        absent_deduction_overridden: overrides.absent ? 1 : 0,
        salary_advance_overridden: overrides.advance ? 1 : 0,
        loan_installment_skipped: overrides.loan ? 1 : 0,
        has_manual_adjustments: (overrides.overtime || overrides.late || overrides.absent || overrides.advance || overrides.loan) ? 1 : 0,
        override_notes: document.getElementById('override-notes').value,
        
        // Status
        slip_status: status,
        
        // IDs from calculation
        advance_request_ids: calculatedData?.advance_request_ids || null,
        loan_ids: calculatedData?.loan_ids || null
    };
    
    // Calculate totals
    data.gross_salary = data.base_salary + data.overtime_amount + data.bonuses + data.allowances + data.other_earnings;
    data.total_deductions = data.late_deduction + data.absent_deduction + data.salary_advance + data.loan_installment + data.tax_deduction + data.other_deductions;
    data.net_salary = data.gross_salary - data.total_deductions;
    
    if (!confirm(`${status === 'draft' ? 'Save this salary slip as draft?' : 'Approve and finalize this salary slip?'}\n\nNet Salary: ${formatCurrency(data.net_salary)}`)) {
        return;
    }
    
    // Save
    fetch('{{ route("hr.salary-slips.store") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Salary slip saved successfully!');
            window.location.href = '{{ route("hr.salary-slips.index") }}';
        } else {
            alert('Error: ' + (data.message || 'Failed to save'));
        }
    })
    .catch(error => {
        console.error('Error saving salary slip:', error);
        alert('Error saving salary slip');
    });
}

async function openAttendanceReport() {
    if (!currentUserId || !currentMonth) {
        alert('Please calculate salary first to view attendance report');
        return;
    }
    
    try {
        // Fetch the attendance data for this specific user
        const response = await fetch(`/attendance/monthly-report?month=${currentMonth}`);
        const result = await response.json();
        
        if (!result.success) {
            alert('Error loading attendance data');
            return;
        }
        
        // Find this employee's data
        const employeeData = result.data.find(emp => emp.user_id == currentUserId);
        
        if (!employeeData) {
            alert('No attendance data found for this employee');
            return;
        }
        
        // Show the modal with this employee's data
        showSalaryAttendanceModal(employeeData);
        
    } catch (error) {
        console.error('Error loading attendance:', error);
        alert('Error loading attendance data');
    }
}

function showSalaryAttendanceModal(employee) {
    const modal = document.getElementById('salaryAttendanceModal');
    const modalName = document.getElementById('salaryModalEmployeeName');
    const modalMonth = document.getElementById('salaryModalMonthYear');
    const modalPresent = document.getElementById('salaryModalStatPresent');
    const modalAbsent = document.getElementById('salaryModalStatAbsent');
    const modalLeave = document.getElementById('salaryModalStatLeave');
    const modalLate = document.getElementById('salaryModalStatLate');
    const modalOT = document.getElementById('salaryModalStatOT');
    const modalHours = document.getElementById('salaryModalStatHours');
    const body = document.getElementById('salaryDailyDetailsBody');
    
    // Populate header
    modalName.textContent = employee.fullname || 'Unknown';
    modalMonth.textContent = new Date(currentMonth + '-02').toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    
    // Populate stats
    modalPresent.textContent = employee.present_days || 0;
    modalAbsent.textContent = employee.absent_days || 0;
    modalLeave.textContent = employee.leave_days || 0;
    modalLate.textContent = employee.late_days || 0;
    modalOT.textContent = employee.overtime_days || 0;
    modalHours.textContent = (employee.total_hours || 0).toFixed(1) + 'h';
    
    // Add hours/minutes for late and overtime
    // API returns 'total_late_minutes' and 'total_overtime_minutes'
    const lateMinutes = Math.round(employee.total_late_minutes || 0);
    const lateHours = Math.floor(lateMinutes / 60);
    const lateMins = lateMinutes % 60;
    document.getElementById('salaryModalStatLateHours').textContent = `${lateHours}h ${lateMins}m`;
    
    const otMinutes = Math.round(employee.total_overtime_minutes || 0);
    const otHours = Math.floor(otMinutes / 60);
    const otMins = otMinutes % 60;
    document.getElementById('salaryModalStatOTHours').textContent = `${otHours}h ${otMins}m`;
    
    // Populate daily details
    if (!employee.daily || employee.daily.length === 0) {
        body.innerHTML = '<tr><td colspan="7" style="padding: 32px; text-align: center; color: #6b7280;">No daily records found for this month</td></tr>';
    } else {
        // Deduplicate records
        const uniqueDaily = [];
        const seenDates = new Set();
        
        for (const day of employee.daily) {
            if (!seenDates.has(day.attendance_date)) {
                seenDates.add(day.attendance_date);
                uniqueDaily.push(day);
            }
        }
        
        body.innerHTML = uniqueDaily.map(day => {
            const isAbsent = day.status === 'absent' || (!day.login_time && !day.logout_time);
            const loginTime = day.login_time || '-';
            const logoutTime = day.logout_time || '-';
            const hours = isAbsent ? '-' : salaryCalculateHours(day.login_time, day.logout_time);
            // ⭐ 5-Oct-2026: the server's ONE-engine figure (frozen snapshot + manager waive).
            const lateBy = isAbsent ? { duration: '-', isLate: false }
                : (day.late_minutes != null ? salaryServerLateBy(day) : salaryCalculateLateBy(day.login_time, day.shift_start));
            const overtime = isAbsent ? { duration: '-', hasOvertime: false } : salaryCalculateOvertime(day.logout_time, day.shift_end);
            const status = isAbsent ? 'Absent' : salaryGetStatus(day.login_time, day.shift_start);
            
            const date = new Date(day.attendance_date + 'T00:00:00');
            const dateStr = `${date.toLocaleDateString('en-US', { month: 'short' })} ${date.getDate()}`;
            const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
            
            return `
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 12px 16px;">
                        <div style="font-weight: 600; color: #111827;">${dateStr}</div>
                        <div style="font-size: 11px; color: #6b7280;">${dayName}</div>
                    </td>
                    <td style="padding: 12px 16px; color: #374151;">${loginTime}</td>
                    <td style="padding: 12px 16px; color: #374151;">${logoutTime}</td>
                    <td style="padding: 12px 16px; text-align: center; color: #374151; font-weight: 500;">${hours}</td>
                    <td style="padding: 12px 16px; text-align: center;">
                        <span style="color: ${lateBy.isLate ? '#dc2626' : '#6b7280'}; font-weight: ${lateBy.isLate ? '600' : '400'};">
                            ${lateBy.duration}
                        </span>
                    </td>
                    <td style="padding: 12px 16px; text-align: center;">
                        <span style="color: ${overtime.hasOvertime ? '#16a34a' : '#6b7280'}; font-weight: ${overtime.hasOvertime ? '600' : '400'};">
                            ${overtime.duration}
                        </span>
                    </td>
                    <td style="padding: 12px 16px; text-align: center;">
                        <span style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; ${
                            status === 'On Time' ? 'background: #dcfce7; color: #166534;' :
                            status === 'Late' ? 'background: #fee2e2; color: #991b1b;' :
                            'background: #f3f4f6; color: #6b7280;'
                        }">
                            ${status}
                        </span>
                    </td>
                </tr>
            `;
        }).join('');
    }
    
    // Show modal
    modal.style.display = 'flex';
    modal.classList.remove('hidden');
}

function closeSalaryAttendanceModal() {
    const modal = document.getElementById('salaryAttendanceModal');
    modal.style.display = 'none';
    modal.classList.add('hidden');
}

// Helper functions for attendance calculations
function salaryCalculateHours(login, logout) {
    if (!login || !logout) return '-';
    const start = new Date('2000-01-01 ' + login);
    const end = new Date('2000-01-01 ' + logout);
    if (end < start) end.setDate(end.getDate() + 1);
    const diff = (end - start) / 1000 / 60 / 60;
    return diff.toFixed(1) + 'h';
}

// A day's lateness as the server's engine gave it: what COUNTS, with any waive named.
function salaryServerLateBy(day) {
    const n = Number(day.late_minutes) || 0;
    const raw = (day.late_raw_minutes != null) ? Number(day.late_raw_minutes) : n;
    const w = Number(day.late_waived_minutes) || 0;
    const f = (m) => { const h = Math.floor(m / 60), mm = m % 60; return h > 0 ? `${h}h ${mm}m` : `${mm}m`; };
    if (raw <= 0) return { isLate: false, duration: '-' };
    return { isLate: true, duration: f(n) + (w > 0 ? ` (${f(w)} waived)` : '') };
}

function salaryCalculateLateBy(login, shiftStart) {
    if (!login || !shiftStart) return { duration: '-', isLate: false };
    const loginTime = new Date('2000-01-01 ' + login);
    const shiftTime = new Date('2000-01-01 ' + shiftStart);
    const diff = (loginTime - shiftTime) / 1000 / 60;
    
    if (diff <= 0) return { duration: '-', isLate: false };
    
    const hours = Math.floor(diff / 60);
    const mins = Math.floor(diff % 60);
    const display = hours > 0 ? `${hours}h ${mins}m` : `${mins}m`;
    
    return { duration: display, isLate: true };
}

function salaryCalculateOvertime(logout, shiftEnd) {
    if (!logout || !shiftEnd) return { duration: '-', hasOvertime: false };
    let logoutTime = new Date('2000-01-01 ' + logout);
    const shiftTime = new Date('2000-01-01 ' + shiftEnd);
    
    if (logoutTime < shiftTime) logoutTime.setDate(logoutTime.getDate() + 1);
    
    const diff = (logoutTime - shiftTime) / 1000 / 60;
    
    if (diff <= 0) return { duration: '-', hasOvertime: false };
    
    const hours = Math.floor(diff / 60);
    const mins = Math.floor(diff % 60);
    const display = hours > 0 ? `${hours}h ${mins}m` : `${mins}m`;
    
    return { duration: display, hasOvertime: true };
}

function salaryGetStatus(login, shiftStart) {
    if (!login || !shiftStart) return 'Absent';
    const loginTime = new Date('2000-01-01 ' + login);
    const shiftTime = new Date('2000-01-01 ' + shiftStart);
    return loginTime <= shiftTime ? 'On Time' : 'Late';
}

// Auto-calculate absent deduction when absent days change
function autoCalculateAbsentDeduction() {
    if (!calculatedData || !calculatedData.working_days) return;
    
    const absentDays = parseInt(document.getElementById('absent-days').value) || 0;
    const baseSalary = parseFloat(document.getElementById('base-salary').value) || 0;
    const workingDays = calculatedData.working_days || 1; // Prevent division by zero
    
    // Calculate per-day salary rate
    const perDaySalary = baseSalary / workingDays;
    
    // Calculate absent deduction
    const absentDeduction = absentDays * perDaySalary;
    
    // Update the deduction field
    document.getElementById('absent-deduction').value = absentDeduction.toFixed(2);
    
    // Update totals
    updateTotals();
}

function resetForm() {
    if (confirm('Start over? All entered data will be lost.')) {
        document.getElementById('step-1').classList.remove('hidden');
        document.getElementById('step-2').classList.add('hidden');
        calculatedData = null;
        overrides = { overtime: false, late: false, absent: false, loan: false };
    }
}

function formatCurrency(amount) {
    return 'PKR ' + parseFloat(amount).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function formatMonth(date) {
    // Parse as local date to avoid timezone issues
    // When date is '2025-10-01', we want October 2025, not September due to UTC conversion
    const parts = date.split('-');
    const year = parseInt(parts[0]);
    const month = parseInt(parts[1]) - 1; // JavaScript months are 0-indexed
    
    const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                    'July', 'August', 'September', 'October', 'November', 'December'];
    return `${months[month]} ${year}`;
}
</script>
@endpush

