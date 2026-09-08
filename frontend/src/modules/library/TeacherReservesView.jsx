import React, { useState, useEffect } from 'react';
import api from '../../api';

export default function TeacherReservesView({ user, books, fetchData }) {
  const [sections, setSections] = useState([]);
  const [allStudents, setAllStudents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [activeSectionIdForStudent, setActiveSectionIdForStudent] = useState(null);
  const [newStudentId, setNewStudentId] = useState('');
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [newSectionName, setNewSectionName] = useState('');
  
  const [showReserveModal, setShowReserveModal] = useState(false);
  const [reserveForm, setReserveForm] = useState({
    section_id: '',
    book_id: '',
    copies_requested: 1,
    target_group: '',
    teacher_to_admin_note: '',
    teacher_to_student_note: ''
  });

  useEffect(() => {
    fetchSections();
    fetchStudents();
  }, []);

  const fetchSections = async () => {
    try {
      setLoading(true);
      const res = await api.get('/library/sections');
      setSections(res.data);
    } catch (error) {
      console.error("Failed to fetch sections", error);
    } finally {
      setLoading(false);
    }
  };

  const fetchStudents = async () => {
    try {
      const res = await api.get('/library/students');
      setAllStudents(res.data);
    } catch (error) {
      console.error("Failed to fetch students", error);
    }
  };

  const submitCreateSection = async () => {
    if (!newSectionName) return;
    try {
      await api.post('/library/sections', { name: newSectionName });
      setNewSectionName('');
      setShowCreateModal(false);
      fetchSections();
    } catch (error) {
      alert(`Failed to create section: ${error.response?.data?.message || error.message}`);
    }
  };

  const handleAddStudent = async (sectionId) => {
    if (!newStudentId) return alert("Please enter or select a Student ID.");
    try {
      await api.post(`/library/sections/${sectionId}/students`, { student_id: newStudentId });
      setNewStudentId('');
      setActiveSectionIdForStudent(null);
      fetchSections();
    } catch (error) {
      alert(`Failed to add student: ${error.response?.data?.message || error.message}`);
    }
  };

  const handleRemoveStudent = async (sectionId, studentId) => {
    if (!window.confirm("Remove this student from the section?")) return;
    try {
      await api.delete(`/library/sections/${sectionId}/students/${studentId}`);
      fetchSections();
    } catch (error) {
      alert(`Failed to remove student: ${error.response?.data?.message || error.message}`);
    }
  };

  const handleRenew = async (transactionId) => {
    try {
      await api.post('/library/loans/renew', { transaction_id: transactionId });
      alert("Book renewed successfully!");
      fetchSections();
      if (fetchData) fetchData();
    } catch (error) {
      console.error(error);
      alert(`Renewal failed: ${error.response?.data?.message || error.message}`);
    }
  };

  const submitReserveRequest = async (e) => {
    e.preventDefault();
    if (!reserveForm.section_id || !reserveForm.book_id) {
      return alert("Please select a section and a book.");
    }
    try {
      await api.post('/library/reserves', reserveForm);
      alert('Course reserve requested successfully!');
      setShowReserveModal(false);
      setReserveForm({
        section_id: '',
        book_id: '',
        copies_requested: 1,
        target_group: '',
        teacher_to_admin_note: '',
        teacher_to_student_note: ''
      });
      fetchSections();
    } catch (error) {
      alert(`Failed to request reserve: ${error.response?.data?.message || error.message}`);
    }
  };

  const renderHeader = () => (
    <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 bg-white rounded-[1.25rem] p-5 shadow-xs border border-slate-200/70 mb-5">
      <div>
        <h2 className="text-[17px] font-extrabold text-[#0f172a] leading-tight">My Course Sections & Reserves</h2>
        <p className="text-gray-500 text-[12px] font-medium mt-1">Manage your sections, students, and course reserves.</p>
      </div>
      <div className="flex gap-2">
        <button
          onClick={() => setShowCreateModal(true)}
          className="bg-emerald-600 text-white text-[11px] font-extrabold py-2 px-4 rounded-xl hover:bg-emerald-700 transition-colors shadow-xs"
        >
          + Create Section
        </button>
        <button
          onClick={() => setShowReserveModal(true)}
          className="bg-[#8B1A24] text-white text-[11px] font-extrabold py-2 px-4 rounded-xl hover:bg-[#6b141c] transition-colors shadow-xs flex items-center gap-1.5"
        >
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={3} stroke="currentColor" className="w-3 h-3">
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
          </svg>
          Request Course Reserve
        </button>
      </div>
    </div>
  );

  const renderCreateModal = () => {
    if (!showCreateModal) return null;
    return (
      <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-[100] p-4">
        <div className="bg-white rounded-[2rem] p-6 w-full max-w-md shadow-xl border border-gray-100">
          <h2 className="text-[20px] font-extrabold text-[#0f172a] mb-4">Create New Section</h2>
          <div className="mb-5">
            <label className="block text-[12px] font-bold text-slate-500 uppercase tracking-wider mb-2">Section Name</label>
            <input 
              type="text" 
              placeholder="e.g., Biology 101 - Fall 2026"
              value={newSectionName}
              onChange={(e) => setNewSectionName(e.target.value)}
              className="w-full text-[13px] font-medium p-3 rounded-xl border border-gray-200 focus:outline-none focus:ring-2 focus:ring-sky-500"
              autoFocus
              onKeyDown={(e) => e.key === 'Enter' && submitCreateSection()}
            />
          </div>
          <div className="flex gap-2 justify-end">
            <button 
              onClick={() => {
                setShowCreateModal(false);
                setNewSectionName('');
              }}
              className="px-4 py-2 text-[12px] font-bold text-slate-500 hover:text-slate-700"
            >
              Cancel
            </button>
            <button 
              onClick={submitCreateSection}
              className="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2 rounded-xl text-[12px] font-extrabold shadow-sm transition-colors"
            >
              Create Section
            </button>
          </div>
        </div>
      </div>
    );
  };

  const renderReserveModal = () => {
    if (!showReserveModal) return null;
    return (
      <div className="fixed inset-0 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 z-[100]">
        <div className="bg-white rounded-[1.5rem] p-5 shadow-2xl border border-slate-100 max-w-md w-full">
          <div className="flex justify-between items-start mb-3">
            <div>
              <span className="inline-block bg-[#0369a1] text-white text-[8.5px] font-black uppercase px-2 py-0.5 rounded tracking-wider mb-1">
                FACULTY REQUEST
              </span>
              <h2 className="text-[16px] font-extrabold text-[#0f172a] leading-tight">
                Request Course Reserve
              </h2>
            </div>
            <button
              onClick={() => setShowReserveModal(false)}
              className="text-slate-400 hover:text-slate-600 text-lg font-bold p-1 cursor-pointer"
            >
              ✕
            </button>
          </div>

          <form onSubmit={submitReserveRequest} className="flex flex-col gap-3">
            <div>
              <label className="block text-[10.5px] font-extrabold text-[#0f172a] mb-1">Course Section *</label>
              <select 
                required 
                className="w-full border border-slate-200 rounded-xl py-1.5 px-3 text-xs font-medium text-[#0f172a] bg-white"
                value={reserveForm.section_id}
                onChange={(e) => setReserveForm({...reserveForm, section_id: e.target.value})}
              >
                <option value="">Select Section...</option>
                {sections.map(sec => <option key={sec.section_id} value={sec.section_id}>{sec.name}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-[10.5px] font-extrabold text-[#0f172a] mb-1">Book Title *</label>
              <select 
                required 
                className="w-full border border-slate-200 rounded-xl py-1.5 px-3 text-xs font-medium text-[#0f172a] bg-white"
                value={reserveForm.book_id}
                onChange={(e) => setReserveForm({...reserveForm, book_id: e.target.value})}
              >
                <option value="">Select Book from Catalog...</option>
                {books?.map(book => <option key={book.id} value={book.id}>{book.title}</option>)}
              </select>
            </div>
            <div className="flex gap-3">
              <div className="flex-1">
                <label className="block text-[10.5px] font-extrabold text-[#0f172a] mb-1">Copies Requested</label>
                <input 
                  type="number" min="1" max="10" required 
                  className="w-full border border-slate-200 rounded-xl py-1.5 px-3 text-xs font-bold text-[#0f172a]" 
                  value={reserveForm.copies_requested}
                  onChange={(e) => setReserveForm({...reserveForm, copies_requested: e.target.value})}
                />
              </div>
              <div className="flex-1">
                <label className="block text-[10.5px] font-extrabold text-[#0f172a] mb-1">Target Group</label>
                <input 
                  type="text" placeholder="e.g., Juniors" 
                  className="w-full border border-slate-200 rounded-xl py-1.5 px-3 text-xs font-medium text-[#0f172a]" 
                  value={reserveForm.target_group}
                  onChange={(e) => setReserveForm({...reserveForm, target_group: e.target.value})}
                />
              </div>
            </div>
            <div>
              <label className="block text-[10.5px] font-extrabold text-[#0f172a] mb-1">Note to Admin (Optional)</label>
              <textarea 
                rows="2" placeholder="Required for midterm..." 
                className="w-full border border-slate-200 rounded-xl py-1.5 px-3 text-xs font-medium text-[#0f172a]"
                value={reserveForm.teacher_to_admin_note}
                onChange={(e) => setReserveForm({...reserveForm, teacher_to_admin_note: e.target.value})}
              ></textarea>
            </div>
            <div>
              <label className="block text-[10.5px] font-extrabold text-[#0f172a] mb-1">Note to Students (Optional)</label>
              <textarea 
                rows="2" placeholder="Read Chapter 3-5..." 
                className="w-full border border-slate-200 rounded-xl py-1.5 px-3 text-xs font-medium text-[#0f172a]"
                value={reserveForm.teacher_to_student_note}
                onChange={(e) => setReserveForm({...reserveForm, teacher_to_student_note: e.target.value})}
              ></textarea>
            </div>

            <div className="flex justify-end gap-2.5 pt-2.5 border-t border-slate-100 mt-1">
              <button
                type="button"
                onClick={() => setShowReserveModal(false)}
                className="px-3.5 py-1.5 border border-slate-200 rounded-xl text-xs font-extrabold hover:bg-slate-50 cursor-pointer"
              >
                Cancel
              </button>
              <button
                type="submit"
                className="px-3.5 py-1.5 bg-[#8B1A24] text-white rounded-xl text-xs font-extrabold hover:bg-[#6b141c] cursor-pointer"
              >
                Submit Request
              </button>
            </div>
          </form>
        </div>
      </div>
    );
  };

  if (loading) {
    return (
      <div className="flex flex-col gap-4">
        {renderHeader()}
        {Array.from({ length: 2 }).map((_, i) => (
          <div key={i} className="bg-white rounded-[1.25rem] p-6 shadow-xs border border-slate-200/70 animate-pulse">
            <div className="h-4 bg-slate-200 rounded w-1/4 mb-4"></div>
            <div className="h-10 bg-slate-200 rounded w-full"></div>
          </div>
        ))}
      </div>
    );
  }

  if (sections.length === 0) {
    return (
      <div className="flex flex-col gap-4">
        {renderHeader()}
        <div className="bg-white rounded-[1.25rem] p-10 text-center border border-slate-200/70 shadow-xs">
          <p className="text-slate-500 font-bold">You don't have any active course sections yet. Create one to get started!</p>
        </div>
        {renderCreateModal()}
        {renderReserveModal()}
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      {renderHeader()}
      
      {sections.map(section => (
        <div key={section.section_id} className="bg-white rounded-[1.25rem] p-6 shadow-xs border border-slate-200/70">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between mb-4 pb-3 border-b border-slate-100 gap-3">
            <div>
              <div className="flex items-center gap-2">
                <span className="bg-[#8B1A24] text-white text-[10px] font-black uppercase px-2.5 py-0.5 rounded tracking-wider">
                  Section
                </span>
                <h2 className="text-[18px] font-extrabold text-[#0f172a]">{section.name}</h2>
              </div>
            </div>
            <button
              onClick={() => setActiveSectionIdForStudent(activeSectionIdForStudent === section.section_id ? null : section.section_id)}
              className="text-[11px] font-extrabold text-[#0369a1] hover:text-[#0284c7] bg-sky-50 px-3 py-1.5 rounded-lg"
            >
              + Add Student
            </button>
          </div>

          {activeSectionIdForStudent === section.section_id && (
            <div className="mb-4 bg-slate-50 p-3 rounded-xl border border-slate-200 flex flex-col sm:flex-row gap-2 items-center">
              <input 
                type="text" 
                placeholder="Student ID (e.g., 3)"
                value={newStudentId}
                onChange={(e) => setNewStudentId(e.target.value)}
                className="text-[12px] p-2 border border-slate-300 rounded-lg w-full sm:w-auto focus:outline-none focus:ring-1 focus:ring-sky-500"
              />
              <span className="text-slate-400 text-[11px] font-bold">OR</span>
              <select 
                className="text-[12px] p-2 border border-slate-300 rounded-lg w-full sm:w-64 focus:outline-none focus:ring-1 focus:ring-sky-500"
                value={newStudentId}
                onChange={(e) => setNewStudentId(e.target.value)}
              >
                <option value="">Select a Student...</option>
                {allStudents.map(student => (
                  <option key={student.user_id} value={student.user_id}>
                    {student.username} (ID: {student.user_id})
                  </option>
                ))}
              </select>
              <button 
                onClick={() => handleAddStudent(section.section_id)}
                className="bg-[#0369a1] text-white px-4 py-2 rounded-lg text-[11px] font-extrabold w-full sm:w-auto"
              >
                Add
              </button>
            </div>
          )}

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {/* Reserves for this section */}
            <div>
              <h3 className="text-[12px] font-extrabold text-slate-500 uppercase tracking-wider mb-3">Course Reserves</h3>
              {section.reserves && section.reserves.length > 0 ? (
                <div className="flex flex-col gap-3">
                  {section.reserves.map(reserve => (
                    <div key={reserve.reserve_id} className="border border-sky-200 bg-sky-50/30 rounded-xl p-4">
                      <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">
                        {reserve.book?.book_title}
                      </h3>
                      <div className="flex items-center gap-2 mb-3">
                        <span className="text-[10px] font-bold text-slate-500 uppercase">Status: {reserve.status}</span>
                      </div>

                      {/* Physical Copies and Borrowers */}
                      <div className="mt-3 border-t border-sky-100 pt-3">
                        <h4 className="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider mb-2">Allocated Copies</h4>
                        {reserve.copies && reserve.copies.length > 0 ? (
                          <div className="flex flex-col gap-2">
                            {reserve.copies.map(copy => {
                              const activeTx = copy.active_transaction;
                              return (
                                <div key={copy.copy_id} className="flex flex-col bg-white border border-slate-200 p-2.5 rounded-lg shadow-2xs gap-2">
                                  <div className="flex justify-between items-start">
                                    <div>
                                      <p className="text-[11px] font-extrabold text-[#0f172a]">{copy.barcode || `CPY-${copy.copy_id}`}</p>
                                    </div>
                                    <span className={`text-[9px] font-bold px-2 py-0.5 rounded-full ${activeTx ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'}`}>
                                      {activeTx ? 'Checked Out' : 'Available'}
                                    </span>
                                  </div>

                                  {activeTx && (
                                    <div className="flex items-center justify-between mt-1 pt-1 border-t border-slate-100">
                                      <div>
                                        <p className="text-[10px] text-slate-500 font-medium">
                                          User: <span className="font-extrabold text-[#0f172a]">{activeTx.user?.username}</span>
                                        </p>
                                        <p className="text-[9px] text-slate-400">Due: {new Date(activeTx.due_date).toLocaleDateString()}</p>
                                      </div>
                                      <button
                                        onClick={() => handleRenew(activeTx.transaction_id)}
                                        className="bg-emerald-600 hover:bg-emerald-700 text-white text-[9px] font-extrabold py-1 px-2 rounded transition-colors"
                                      >
                                        Renew
                                      </button>
                                    </div>
                                  )}
                                </div>
                              );
                            })}
                          </div>
                        ) : (
                          <p className="text-[10px] text-slate-500 italic">No physical copies allocated yet.</p>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-[11px] text-slate-500 italic border border-slate-100 rounded-xl p-4 bg-slate-50 text-center">No course reserves for this section.</p>
              )}
            </div>

            {/* Students in this section */}
            <div>
              <h3 className="text-[12px] font-extrabold text-slate-500 uppercase tracking-wider mb-3">Enrolled Students</h3>
              {section.students && section.students.length > 0 ? (
                <div className="flex flex-col gap-2">
                  {section.students.map(studentEntry => (
                    <div key={studentEntry.id} className="flex items-center justify-between bg-white border border-slate-200 p-2.5 rounded-xl shadow-2xs">
                      <div>
                        <p className="text-[12px] font-extrabold text-[#0f172a]">{studentEntry.student?.username}</p>
                        <p className="text-[10px] text-slate-400">ID: {studentEntry.student_id}</p>
                      </div>
                      <button 
                        onClick={() => handleRemoveStudent(section.section_id, studentEntry.student_id)}
                        className="text-red-500 hover:text-red-700 text-[10px] font-extrabold bg-red-50 hover:bg-red-100 px-2 py-1 rounded"
                      >
                        Remove
                      </button>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-[11px] text-slate-500 italic border border-slate-100 rounded-xl p-4 bg-slate-50 text-center">No students added to this section.</p>
              )}
            </div>
          </div>
        </div>
      ))}

      {renderCreateModal()}
      {renderReserveModal()}
    </div>
  );
}
