import folders from './folders';
import taskPhotoUpload from './task-photo-upload';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mediaFolders', folders);
    window.Alpine.data('taskPhotoUpload', taskPhotoUpload);
});
