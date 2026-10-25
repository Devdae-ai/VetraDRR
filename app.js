const mapConfig = window.dashboardMapConfig || {};
const mapCanvas = document.getElementById('mapCanvas');
let map = null;
let currentLocationMarker = null;

if (mapCanvas && window.L) {
  const initialLocation = Array.isArray(mapConfig.initialLocation)
    ? mapConfig.initialLocation.map(Number)
    : [12.8797, 121.774];

  try {
    map = L.map(mapCanvas, {zoomControl:true}).setView(initialLocation, mapConfig.isAdmin ? 6 : 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution:'© OpenStreetMap contributors'
    }).addTo(map);

    if (mapConfig.isAdmin) {
      const riskColors = {Danger:'#ff4d4d', Caution:'#ffad33', Safe:'#43d18b'};
      const locations = [];
      (mapConfig.households || []).forEach((household) => {
        const latitude = Number(household.latitude);
        const longitude = Number(household.longitude);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;

        const color = riskColors[household.risk_level] || riskColors.Caution;
        L.circleMarker([latitude, longitude], {
          radius:8,
          color,
          fillColor:color,
          fillOpacity:.82,
          weight:2
        }).addTo(map).bindPopup(
          `<b>${escapeMapText(household.family_code)}</b><br>${escapeMapText(household.head_name)}<br>${escapeMapText(household.purok)}<br>Risk: ${escapeMapText(household.risk_level)}`
        );
        locations.push([latitude, longitude]);
      });

      (mapConfig.centers || []).forEach((center) => {
        const latitude = Number(center.latitude);
        const longitude = Number(center.longitude);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;

        L.marker([latitude, longitude]).addTo(map).bindPopup(
          `<b>${escapeMapText(center.name)}</b><br>${escapeMapText(center.barangay)}<br>Status: ${escapeMapText(center.status)}`
        );
        locations.push([latitude, longitude]);
      });

      if (locations.length) {
        map.fitBounds(locations, {padding:[28, 28], maxZoom:12});
      }
    } else {
      currentLocationMarker = L.circleMarker(initialLocation, {
        radius:9,
        color:'#fff',
        weight:3,
        fillColor:'#ff6b2c',
        fillOpacity:1
      }).addTo(map).bindPopup(
        mapConfig.hasReportedLocation ? 'Your latest reported location' : 'Philippines map preview'
      );
      if (mapConfig.hasReportedLocation) {
        currentLocationMarker.openPopup();
      }
    }

    window.addEventListener('load', () => map.invalidateSize());
  } catch (error) {
    console.error('Map initialization failed:', error);
    mapCanvas.textContent = 'The map could not be loaded. Check your internet connection and reload.';
  }
} else if (mapCanvas) {
  mapCanvas.textContent = 'The map library could not be loaded. Check your internet connection and reload.';
}

function escapeMapText(value) {
  return String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&':'&amp;',
    '<':'&lt;',
    '>':'&gt;',
    '"':'&quot;',
    "'":'&#39;'
  })[character]);
}

window.updateLocationPreview = (latitude, longitude) => {
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
      || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
    return;
  }

  if (map) {
    map.setView([latitude, longitude], 15);
    if (currentLocationMarker) {
      currentLocationMarker.setLatLng([latitude, longitude]);
    } else {
      currentLocationMarker = L.circleMarker([latitude, longitude], {
        radius:9,
        color:'#fff',
        weight:3,
        fillColor:'#ff6b2c',
        fillOpacity:1
      }).addTo(map);
    }
    currentLocationMarker.bindPopup('Your current GPS location').openPopup();
  }

  const zoomEarthLink = document.getElementById('zoomEarthLink');
  if (zoomEarthLink) {
    zoomEarthLink.href = `https://zoom.earth/places/philippines/#view=${latitude.toFixed(5)},${longitude.toFixed(5)},10z`;
  }
};

window.locateMap = () => {
  if (!navigator.geolocation) {
    alert('GPS is not supported in this browser.');
    return;
  }

  navigator.geolocation.getCurrentPosition((position) => {
    window.updateLocationPreview(position.coords.latitude, position.coords.longitude);
  }, () => {
    alert('Location access was denied. Please allow GPS access and try again.');
  }, {enableHighAccuracy:true, timeout:15000});
};

function renderMapSnapshot(data) {
  if (!data) return;

  if (data.weather) {
    const weatherSummary = document.getElementById('weatherSummary');
    const weatherMeta = document.getElementById('weatherMeta');
    const weatherTemp = document.getElementById('weatherTemp');
    const weatherIcon = document.getElementById('weatherIcon');
    const riskAlertTitle = document.getElementById('riskAlertTitle');
    const riskAlertText = document.getElementById('riskAlertText');

    const weatherCard = document.getElementById('weatherCard');
    const summary = data.weather.summary || 'Monitoring';
    if (weatherSummary) {
      weatherSummary.textContent = summary;
    }
    if (weatherMeta) {
      weatherMeta.textContent = data.weather.meta || 'Satellite + rain + storm check';
    }
    if (weatherTemp) {
      weatherTemp.textContent = `${data.weather.temp ?? '--'}°C`;
    }
    if (weatherIcon) {
      weatherIcon.textContent = data.weather.icon || '☀️';
    }
    if (weatherCard) {
      weatherCard.classList.remove('sunny', 'rainy', 'storm');
      const condition = (summary || '').toLowerCase();
      if (condition.includes('rain') || condition.includes('drizzle') || condition.includes('storm') || condition.includes('showers')) {
        weatherCard.classList.add('rainy');
      } else if (condition.includes('clear') || condition.includes('sun') || condition.includes('cloud')) {
        weatherCard.classList.add('sunny');
      } else {
        weatherCard.classList.add('storm');
      }
    }
    if (riskAlertTitle) {
      riskAlertTitle.textContent = (data.weather.calamityAlert && data.weather.calamityAlert.toLowerCase().includes('watch')) ? 'Calamity watch' : 'Monitoring';
    }
    if (riskAlertText) {
      riskAlertText.textContent = data.weather.calamityAlert || 'No immediate calamity alert detected. Continue monitoring the live feed.';
    }
  }
}

async function refreshLiveData() {
  try {
    const response = await fetch('api.php?action=overview', {headers:{'X-Requested-With':'XMLHttpRequest'}});
    if (!response.ok) return;
    const data = await response.json();
    renderMapSnapshot(data);
    if (Array.isArray(data.chat)) {
      renderChat(data.chat);
    }
  } catch (error) {
    console.warn('Live refresh failed:', error);
  }
}

function renderChat(messages) {
  const chatMessages = document.getElementById('chatMessages');
  if (!chatMessages) return;

  if (!Array.isArray(messages) || !messages.length) {
    chatMessages.innerHTML = '<p class="muted">No messages yet. Start the conversation.</p>';
    return;
  }

  const currentRole = document.body.dataset.userRole || 'civilian';
  chatMessages.innerHTML = messages.map((message) => {
    const isMine = (message.user_role || 'civilian') === currentRole;
    return `
      <div class="chat-bubble ${isMine ? 'me' : 'them'}">
        <div class="meta">
          <span>${message.user_name || 'System'}</span>
          <span>${message.created_at || ''}</span>
        </div>
        <div class="text">${message.message || ''}</div>
      </div>
    `;
  }).join('');

  chatMessages.scrollTop = chatMessages.scrollHeight;
}

async function loadChat() {
  try {
    const response = await fetch('api.php?action=chat', {headers:{'X-Requested-With':'XMLHttpRequest'}});
    if (!response.ok) return;
    const data = await response.json();
    if (Array.isArray(data.messages)) {
      renderChat(data.messages);
    }
  } catch (error) {
    console.warn('Chat refresh failed:', error);
  }
}

const chatForm = document.getElementById('chatForm');
if (chatForm) {
  chatForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = document.getElementById('chatInput');
    const message = (input.value || '').trim();
    if (!message) return;

    try {
      const response = await fetch('api.php?action=chat', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: `message=${encodeURIComponent(message)}`
      });

      if (response.ok) {
        input.value = '';
        await loadChat();
      }
    } catch (error) {
      console.warn('Chat send failed:', error);
    }
  });
}

const persistReportAssignment = async (reportId, status, assignedTo, assignedTeam, escalationLevel) => {
  try {
    const response = await fetch('api.php?action=update_status', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
      body: new URLSearchParams({
        report_id: reportId,
        status,
        assigned_to: assignedTo,
        assigned_team: assignedTeam,
        escalation_level: escalationLevel,
      }).toString()
    });

    if (!response.ok) {
      const payload = await response.json().catch(() => ({}));
      throw new Error(payload.error || 'Unable to update report assignment.');
    }

    return true;
  } catch (error) {
    console.warn('Status update failed:', error);
    alert(error.message || 'Unable to update report assignment. Please try again.');
    return false;
  }
};

document.querySelectorAll('.report-save').forEach((button) => {
  button.addEventListener('click', async () => {
    const card = button.closest('.center-row');
    if (!card) return;

    const statusSelect = card.querySelector('.report-status');
    const assigneeInput = card.querySelector('.report-assignee');
    const teamSelect = card.querySelector('.report-team');
    const escalationSelect = card.querySelector('.report-escalation');
    const status = statusSelect ? statusSelect.value : '';
    const reportId = statusSelect ? statusSelect.dataset.reportId : '';
    const state = card.querySelector('.report-save-state');

    if (!reportId || !status) return;
    button.disabled = true;
    button.textContent = 'Submitting...';
    if (state) state.textContent = '';

    const saved = await persistReportAssignment(
      reportId,
      status,
      assigneeInput ? assigneeInput.value : '',
      teamSelect ? teamSelect.value : '',
      escalationSelect ? escalationSelect.value : 'Monitoring'
    );

    button.disabled = false;
    button.textContent = 'Submit update';
    if (saved) {
      const statusLabel = card.querySelector('.report-current-status');
      if (statusLabel) statusLabel.textContent = status;
      if (state) state.textContent = 'Update submitted.';
    }
  });
});

window.addEventListener('load', () => {
  loadChat();
  setInterval(() => {
    refreshLiveData();
    loadChat();
  }, 12000);
});
